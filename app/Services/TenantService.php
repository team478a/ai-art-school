<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';

class TenantService {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?: get_pdo();
        $this->ensureTables();
        $this->ensureDefaultTenant();
    }

    public function ensureTables(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tenants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_key VARCHAR(80) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                service_name VARCHAR(255) NULL,
                primary_domain VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                memo TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_tenants_domain (primary_domain),
                INDEX idx_tenants_status (status),
                INDEX idx_tenants_default (is_default)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tenant_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                setting_key VARCHAR(120) NOT NULL,
                setting_value TEXT NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_tenant_setting (tenant_id, setting_key),
                INDEX idx_tenant_settings_tenant (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function ensureDefaultTenant(): void {
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $serviceName = Settings::get('service_name', '') ?: Settings::get('client_service_name', '') ?: 'AIアート教室';
        $name = Settings::get('client_company_name', '') ?: Settings::get('client_name', '') ?: $serviceName;
        $domain = $this->guessDomain();

        $stmt = $this->pdo->prepare("
            INSERT INTO tenants (tenant_key, name, service_name, primary_domain, status, is_default, memo, created_at)
            VALUES ('default', ?, ?, ?, 'active', 1, ?, NOW())
        ");
        $stmt->execute([
            $name,
            $serviceName,
            $domain,
            '既存システムから自動作成された標準クライアントです。',
        ]);
    }

    public function all(): array {
        $stmt = $this->pdo->query("
            SELECT *
            FROM tenants
            ORDER BY is_default DESC, FIELD(status, 'active', 'suspended', 'archived'), id ASC
        ");
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function current(): ?array {
        $settingsTenant = Settings::currentTenant();
        if ($settingsTenant) {
            return $settingsTenant;
        }

        $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        if ($host !== '') {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM tenants
                WHERE LOWER(primary_domain) = ? AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$host]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        $stmt = $this->pdo->query("
            SELECT *
            FROM tenants
            WHERE is_default = 1
            ORDER BY id ASC
            LIMIT 1
        ");
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int {
        $tenantKey = $this->normalizeTenantKey($data['tenant_key'] ?? '');
        $stmt = $this->pdo->prepare("
            INSERT INTO tenants (tenant_key, name, service_name, primary_domain, status, is_default, memo, created_at)
            VALUES (?, ?, ?, ?, ?, 0, ?, NOW())
        ");
        $stmt->execute([
            $tenantKey,
            trim((string)($data['name'] ?? '')),
            trim((string)($data['service_name'] ?? '')),
            $this->normalizeDomain($data['primary_domain'] ?? ''),
            $this->normalizeStatus($data['status'] ?? 'active'),
            trim((string)($data['memo'] ?? '')),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $tenantKey = $this->normalizeTenantKey($data['tenant_key'] ?? '');
        $stmt = $this->pdo->prepare("
            UPDATE tenants
            SET tenant_key = ?, name = ?, service_name = ?, primary_domain = ?, status = ?, memo = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $tenantKey,
            trim((string)($data['name'] ?? '')),
            trim((string)($data['service_name'] ?? '')),
            $this->normalizeDomain($data['primary_domain'] ?? ''),
            $this->normalizeStatus($data['status'] ?? 'active'),
            trim((string)($data['memo'] ?? '')),
            $id,
        ]);
    }

    public function setStatus(int $id, string $status): void {
        $status = $this->normalizeStatus($status);
        $stmt = $this->pdo->prepare('UPDATE tenants SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public function makeDefault(int $id): void {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE tenants SET is_default = 0, updated_at = NOW()');
            $stmt = $this->pdo->prepare("UPDATE tenants SET is_default = 1, status = 'active', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function settings(int $tenantId): array {
        $stmt = $this->pdo->prepare('SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        return $settings;
    }

    public function saveSettings(int $tenantId, array $settings): void {
        $allowed = $this->settingSchema();
        $stmt = $this->pdo->prepare("
            INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        foreach ($allowed as $key => $meta) {
            if (($meta['type'] ?? 'text') === 'checkbox') {
                $value = !empty($settings[$key]) ? '1' : '0';
            } else {
                if (!array_key_exists($key, $settings)) {
                    continue;
                }
                $value = trim((string)$settings[$key]);
            }
            $stmt->execute([$tenantId, $key, $value]);
        }
    }

    public function settingSchema(): array {
        $engineOptions = [
            '' => '未指定（標準設定を使用）',
            'stability' => 'Stability AI',
            'openai' => 'OpenAI',
            'grok' => 'Grok / xAI',
        ];

        return [
            'service_operation_type' => [
                'group' => 'basic',
                'label' => '運用タイプ',
                'type' => 'select',
                'options' => [
                    'class_school' => '教室運営型（予約・承認・出席あり）',
                    'online_generation' => 'オンライン生成型（予約なし）',
                    'hybrid' => 'ハイブリッド型（教室 + オンライン生成）',
                    'event_campaign' => 'イベント・キャンペーン型',
                ],
                'help' => 'このクライアントの主な使い方です。メニュー表示や初期設定確認の基準に使います。',
            ],
            'service_name' => ['group' => 'basic', 'label' => 'サービス名', 'type' => 'text', 'help' => '公開ページや通知文に表示するサービス名です。'],
            'classroom_name' => ['group' => 'basic', 'label' => '教室名・ブランド名', 'type' => 'text'],
            'public_base_url' => ['group' => 'basic', 'label' => '公開URL', 'type' => 'text', 'placeholder' => 'https://example.com'],

            'client_company_name' => ['group' => 'public', 'label' => '会社名・団体名', 'type' => 'text'],
            'client_operator_name' => ['group' => 'public', 'label' => '運営者名', 'type' => 'text'],
            'client_contact_email' => ['group' => 'public', 'label' => '問い合わせメール', 'type' => 'text'],
            'client_contact_phone' => ['group' => 'public', 'label' => '問い合わせ電話番号', 'type' => 'text'],
            'client_address' => ['group' => 'public', 'label' => '所在地', 'type' => 'textarea'],

            'line_official_id' => ['group' => 'line', 'label' => 'LINE公式ID', 'type' => 'text', 'placeholder' => '@xxxxxxx'],
            'line_channel_secret' => ['group' => 'line', 'label' => 'LINE Channel Secret', 'type' => 'password'],
            'line_channel_access_token' => ['group' => 'line', 'label' => 'LINE Channel Access Token', 'type' => 'password'],
            'liff_id' => ['group' => 'line', 'label' => '予約用LIFF ID', 'type' => 'text', 'help' => '予約カレンダーをLINEアプリ内で開くためのLIFF IDです。予約を使わない場合は空でも構いません。'],
            'shop_liff_id' => ['group' => 'line', 'label' => '購入用LIFF ID', 'type' => 'text', 'help' => '回数券・サブスク・一回払いの購入ページを開くためのLIFF IDです。'],
            'generate_liff_id' => ['group' => 'line', 'label' => '画像生成用LIFF ID', 'type' => 'text', 'placeholder' => '例: 2000000000-xxxxxxxx', 'help' => '画像生成ページ専用のLIFF IDです。LINE DevelopersのEndpoint URLには、下に表示される「画像生成 LIFF URL」を登録してください。'],

            'rich_menu_segments_enabled' => ['group' => 'richmenu', 'label' => 'リッチメニュー出し分けを有効にする', 'type' => 'checkbox'],
            'rich_menu_segment_first_time_id' => ['group' => 'richmenu', 'label' => '初回・未参加ユーザー用 Rich Menu ID', 'type' => 'text'],
            'rich_menu_segment_attended_id' => ['group' => 'richmenu', 'label' => '参加済みユーザー用 Rich Menu ID', 'type' => 'text'],
            'rich_menu_segment_ticket_id' => ['group' => 'richmenu', 'label' => '回数券ユーザー用 Rich Menu ID', 'type' => 'text'],
            'rich_menu_segment_subscriber_id' => ['group' => 'richmenu', 'label' => 'サブスクユーザー用 Rich Menu ID', 'type' => 'text'],

            'payment_provider' => [
                'group' => 'shopping',
                'label' => '決済システム',
                'type' => 'select',
                'options' => [
                    'local_stripe' => 'Stripe（既存決済）',
                    'shopping' => 'ショッピングシステム',
                ],
            ],
            'shopping_checkout_base_url' => ['group' => 'shopping', 'label' => 'ショッピング購入URL', 'type' => 'text', 'placeholder' => 'https://shop.example.com/checkout'],
            'shopping_key_id' => ['group' => 'shopping', 'label' => '署名キーID', 'type' => 'text'],
            'shopping_hmac_secret' => ['group' => 'shopping', 'label' => 'HMAC共有シークレット', 'type' => 'password'],
            'shopping_product_map_json' => ['group' => 'shopping', 'label' => '商品コード対応表（JSON）', 'type' => 'textarea', 'placeholder' => '{"monthly":"AIART_MONTHLY","annual":"AIART_ANNUAL","ticket_6":"AIART_TICKET_6","one_time":"AIART_ONCE"}'],
            'shopping_webhook_tolerance_seconds' => ['group' => 'shopping', 'label' => 'Webhook時刻許容秒数', 'type' => 'number'],

            'stripe_secret_key' => ['group' => 'stripe', 'label' => 'Stripeシークレットキー', 'type' => 'password'],
            'stripe_publishable_key' => ['group' => 'stripe', 'label' => 'Stripe公開可能キー', 'type' => 'text'],
            'stripe_webhook_secret' => ['group' => 'stripe', 'label' => 'Stripe Webhook署名シークレット', 'type' => 'password'],
            'stripe_subscription_price_id' => ['group' => 'stripe', 'label' => '月額サブスク Price ID', 'type' => 'text'],
            'stripe_annual_subscription_price_id' => ['group' => 'stripe', 'label' => '年額サブスク Price ID', 'type' => 'text'],
            'one_time_price_id' => ['group' => 'stripe', 'label' => '一回払い Price ID', 'type' => 'text'],
            'ticket_plans' => ['group' => 'stripe', 'label' => '回数券プラン JSON', 'type' => 'textarea', 'placeholder' => '[{"count":6,"amount":5500}]'],

            'openai_api_key' => ['group' => 'ai', 'label' => 'OpenAI APIキー', 'type' => 'password'],
            'stability_api_key' => ['group' => 'ai', 'label' => 'Stability AI APIキー', 'type' => 'password'],
            'grok_api_key' => ['group' => 'ai', 'label' => 'Grok / xAI APIキー', 'type' => 'password'],
            'image_engine' => ['group' => 'ai', 'label' => '通常の画像生成エンジン', 'type' => 'select', 'options' => $engineOptions],
            'image_human_safe_engine' => ['group' => 'ai', 'label' => '人物向け安全エンジン', 'type' => 'select', 'options' => $engineOptions],
            'image_high_quality_engine' => ['group' => 'ai', 'label' => '高品質エンジン', 'type' => 'select', 'options' => $engineOptions],
            'image_quality_level' => ['group' => 'ai', 'label' => '画像品質レベル', 'type' => 'select', 'options' => ['standard' => '標準', 'high' => '高品質']],
            'openai_image_quality' => ['group' => 'ai', 'label' => 'OpenAI画像品質', 'type' => 'select', 'options' => ['auto' => '自動', 'medium' => '中', 'high' => '高']],
            'image_quality_gate_enabled' => ['group' => 'ai', 'label' => '生成後の品質検査', 'type' => 'checkbox'],
            'image_quality_min_width' => ['group' => 'ai', 'label' => '画像の最小幅', 'type' => 'number'],
            'image_quality_min_height' => ['group' => 'ai', 'label' => '画像の最小高さ', 'type' => 'number'],
            'image_quality_duplicate_distance' => ['group' => 'ai', 'label' => '類似画像の許容値', 'type' => 'number'],
            'image_quality_max_regeneration_attempts' => ['group' => 'ai', 'label' => '品質不合格時の再生成回数', 'type' => 'number'],
            'image_quality_vision_check_enabled' => ['group' => 'ai', 'label' => 'AIによる人物・構図検査', 'type' => 'checkbox'],
            'image_quality_vision_model' => ['group' => 'ai', 'label' => '品質検査モデル', 'type' => 'select', 'options' => ['gpt-4o-mini' => 'GPT-4o mini', 'gpt-4o' => 'GPT-4o']],
            'image_quality_vision_min_score' => ['group' => 'ai', 'label' => '品質合格スコア', 'type' => 'text'],
            'image_quality_reject_people_when_forbidden' => ['group' => 'ai', 'label' => '人物なし指定を厳格化', 'type' => 'checkbox'],

            'integration_enabled' => ['group' => 'integration', 'label' => '5システム連携を有効にする', 'type' => 'checkbox'],
            'integration_common_id_base_url' => ['group' => 'integration', 'label' => '共通ID APIベースURL', 'type' => 'text', 'placeholder' => 'https://example.com'],
            'integration_project_key' => ['group' => 'integration', 'label' => 'プロジェクトキー', 'type' => 'text', 'placeholder' => 'ai-art-school'],
            'integration_key_id' => ['group' => 'integration', 'label' => 'HMAC Key ID', 'type' => 'text'],
            'integration_hmac_secret' => ['group' => 'integration', 'label' => 'HMAC Secret', 'type' => 'password'],
            'integration_timeout_seconds' => ['group' => 'integration', 'label' => 'APIタイムアウト（秒）', 'type' => 'number'],
            'line_monthly_limit' => ['group' => 'operation', 'label' => 'LINE月間送信上限', 'type' => 'number'],
            'daily_request_limit' => ['group' => 'operation', 'label' => '1ユーザー1日の最大依頼数', 'type' => 'number'],
            'max_images_per_request' => ['group' => 'operation', 'label' => '1依頼あたり最大生成枚数', 'type' => 'number'],
            'workflow_approval_mode' => ['group' => 'operation', 'label' => '承認方式', 'type' => 'select', 'options' => ['manual' => '手動承認', 'auto' => '自動承認']],
            'workflow_attendance_gate' => [
                'group' => 'operation',
                'label' => '参加・生成条件',
                'type' => 'select',
                'options' => [
                    'approved_and_time_window' => '承認済み + 開催時間内',
                    'approved_only' => '承認済みなら可',
                    'time_window_only' => '時間内なら可',
                    'none' => '制限しない',
                ],
            ],
            'first_visit_free_enabled' => ['group' => 'operation', 'label' => '初回無料を有効にする', 'type' => 'checkbox'],
            'generation_access_mode' => [
                'group' => 'operation',
                'label' => '生成申請の受付方式',
                'type' => 'select',
                'options' => [
                    'class_attendance' => '教室参加者のみ',
                    'time_window_only' => '受付時間内なら生成可能',
                    'class_or_time_window' => '教室参加者または受付時間内',
                    'always_open' => '常時受付',
                ],
                'help' => '予約なしのオンライン生成では「受付時間内なら生成可能」または「常時受付」を選びます。',
            ],
            'generation_online_enabled' => ['group' => 'operation', 'label' => 'オンライン生成を有効にする', 'type' => 'checkbox'],
            'generation_window_start' => ['group' => 'operation', 'label' => '生成受付開始時刻', 'type' => 'time'],
            'generation_window_end' => ['group' => 'operation', 'label' => '生成受付終了時刻', 'type' => 'time'],
            'generation_available_date_start' => ['group' => 'operation', 'label' => '生成可能開始日', 'type' => 'date'],
            'generation_available_date_end' => ['group' => 'operation', 'label' => '生成可能終了日', 'type' => 'date'],
            'generation_available_weekdays' => [
                'group' => 'operation',
                'label' => '生成可能曜日',
                'type' => 'select',
                'options' => [
                    '' => '曜日制限なし',
                    'mon,tue,wed,thu,fri' => '平日のみ（月〜金）',
                    'sat,sun' => '土日のみ',
                    'mon,tue,wed,thu,fri,sat,sun' => '毎日',
                    'mon' => '月曜日のみ',
                    'tue' => '火曜日のみ',
                    'wed' => '水曜日のみ',
                    'thu' => '木曜日のみ',
                    'fri' => '金曜日のみ',
                    'sat' => '土曜日のみ',
                    'sun' => '日曜日のみ',
                ],
            ],
            'generation_period_request_limit' => ['group' => 'operation', 'label' => '期間内の1ユーザー最大生成依頼数', 'type' => 'number'],
            'generation_window_message' => ['group' => 'operation', 'label' => '受付時間外メッセージ', 'type' => 'textarea'],
            'generation_stale_minutes' => ['group' => 'operation', 'label' => '生成停止と判定する時間（分）', 'type' => 'number'],
        ];
    }

    public function normalizeTenantKey(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
        $value = trim((string)$value, '-_');
        return $value !== '' ? substr($value, 0, 80) : ('tenant-' . date('YmdHis'));
    }

    private function normalizeDomain(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('#^https?://#', '', $value);
        $value = preg_replace('#/.*$#', '', (string)$value);
        return preg_replace('/:\d+$/', '', (string)$value) ?: '';
    }

    private function normalizeStatus(string $status): string {
        return in_array($status, ['active', 'suspended', 'archived'], true) ? $status : 'active';
    }

    private function guessDomain(): string {
        $baseUrl = Settings::get('public_base_url', '') ?: Settings::get('site_url', '') ?: '';
        if ($baseUrl !== '') {
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if ($host) {
                return strtolower($host);
            }
        }
        $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
        return preg_replace('/:\d+$/', '', $host) ?: '';
    }
}
