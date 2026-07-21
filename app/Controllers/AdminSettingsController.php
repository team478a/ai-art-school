<?php
require_once BASE_PATH . '/config/settings.php';

class AdminSettingsController {
    public function show(): void {
        if (Settings::get('cron_token', '') === '') {
            Settings::set('cron_token', bin2hex(random_bytes(16)));
        }
        $settings = Settings::all();
        $saved = !empty($_GET['saved']);
        require BASE_PATH . '/app/Views/admin/settings.php';
    }

    public function save(): void {
        $_POST['admin_notify_email'] = isset($_POST['admin_notify_email']) ? '1' : '0';
        $_POST['line_grid_mode'] = isset($_POST['line_grid_mode']) ? '1' : '0';
        $_POST['generation_online_enabled'] = isset($_POST['generation_online_enabled']) ? '1' : '0';

        $allowed = [
            'line_channel_secret', 'line_channel_access_token',
            'claude_api_key', 'openai_api_key', 'openai_image_model', 'photo_illustration_size', 'stability_api_key',
            'image_engine', 'grok_api_key', 'grok_image_model',
            'stability_auto_switch_enabled', 'stability_auto_switch_threshold', 'stability_fallback_engine',
            'image_human_safe_engine', 'image_high_quality_engine', 'image_quality_level', 'openai_image_quality',
            'stability_model', 'image_aspect', 'image_steps', 'image_cfg',
            'prompt_model', 'ng_words',
            'stripe_secret_key', 'stripe_webhook_secret', 'stripe_publishable_key',
            'stripe_subscription_price_id', 'subscription_price_label',
            'stripe_annual_subscription_price_id', 'annual_subscription_price_label',
            'ticket_valid_days',
            'admin_line_user_id', 'admin_notify_email', 'resend_api_key', 'mail_from',
            'terms_url', 'privacy_url',
            'storage_driver', 'storage_public_url',
            'r2_account_id', 'r2_access_key', 'r2_secret_key', 'r2_bucket',
            'max_daily_requests_per_user', 'max_images_per_request',
            'images_per_pattern', 'line_grid_mode', 'line_monthly_limit',
            'generation_access_mode', 'generation_window_start', 'generation_window_end', 'generation_window_message',
            'generation_online_enabled', 'generation_available_date_start', 'generation_available_date_end',
            'generation_available_weekdays', 'generation_period_request_limit',
            'admin_email',
        ];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                Settings::set($key, trim((string)$_POST[$key]));
            }
        }

        if (isset($_POST['ticket_count']) && is_array($_POST['ticket_count'])) {
            $plans = [];
            foreach ($_POST['ticket_count'] as $i => $cnt) {
                $cnt = (int)$cnt;
                $price = (int)($_POST['ticket_price'][$i] ?? 0);
                if ($cnt > 0 && $price > 0) {
                    $plans[] = ['count' => $cnt, 'price' => $price];
                }
            }
            Settings::set('ticket_plans', json_encode($plans, JSON_UNESCAPED_UNICODE));
        }

        if (isset($_POST['notify_events_present'])) {
            $events = $_POST['admin_notify_events'] ?? [];
            Settings::set('admin_notify_events', is_array($events) ? implode(',', $events) : '');
        }

        header('Location: /admin/settings?saved=1');
        exit;
    }

    public function clientSetup(): void {
        $settings = Settings::all();
        $checks = $this->clientSetupChecks();
        $healthChecks = $this->clientRolloutHealthChecks();
        $preflightChecks = $this->clientPreflightChecks();
        $productionChecks = $this->clientProductionChecks();
        $workflow = $this->workflowSettings();
        $templates = $this->clientTemplates();
        $snapshots = $this->clientSetupSnapshots(8);
        $saved = !empty($_GET['saved']);
        require BASE_PATH . '/app/Views/admin/client_setup.php';
    }

    public function saveClientSetup(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        $allowed = [
            'client_name',
            'service_name',
            'classroom_name',
            'service_tagline',
            'public_base_url',
            'client_contact_email',
            'client_contact_phone',
            'client_postal_code',
            'client_address',
            'client_company_name',
            'client_operator_name',
            'client_invoice_name',
            'client_memo',
            'workflow_template',
            'workflow_approval_mode',
            'workflow_payment_mode',
            'workflow_day_notice',
            'workflow_day_notice_mode',
            'workflow_auto_notice_enabled',
            'workflow_attendance_gate',
            'workflow_first_visit_free',
            'workflow_ticket_enabled',
            'workflow_subscription_enabled',
            'workflow_cash_payment_enabled',
            'generation_access_mode',
            'generation_window_start',
            'generation_window_end',
            'generation_window_message',
            'generation_online_enabled',
            'generation_available_date_start',
            'generation_available_date_end',
            'generation_available_weekdays',
            'generation_period_request_limit',
        ];

        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                Settings::set($key, trim((string)$_POST[$key]));
            }
        }

        foreach (['workflow_auto_notice_enabled', 'workflow_first_visit_free', 'workflow_ticket_enabled', 'workflow_subscription_enabled', 'workflow_cash_payment_enabled', 'generation_online_enabled'] as $key) {
            Settings::set($key, isset($_POST[$key]) ? '1' : '0');
        }

        header('Location: /admin/client-setup?saved=1');
        exit;
    }

    public function applyClientWizard(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        $map = [
            'wizard_client_name' => 'client_name',
            'wizard_service_name' => 'service_name',
            'wizard_classroom_name' => 'classroom_name',
            'wizard_public_base_url' => 'public_base_url',
            'wizard_company_name' => 'client_company_name',
            'wizard_contact_email' => 'client_contact_email',
            'wizard_contact_phone' => 'client_contact_phone',
            'wizard_workflow_template' => 'workflow_template',
        ];
        foreach ($map as $postKey => $settingKey) {
            if (isset($_POST[$postKey])) {
                Settings::set($settingKey, trim((string)$_POST[$postKey]));
            }
        }

        $template = trim((string)($_POST['wizard_workflow_template'] ?? 'ai_art_class_standard'));
        $this->applyTemplateValues($template, false);
        $this->writeClientSetupSnapshot('wizard_apply');

        header('Location: /admin/client-setup?saved=wizard');
        exit;
    }

    public function applyClientTemplate(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        $template = trim((string)($_POST['template'] ?? ''));
        if ($template === '' || !isset($this->clientTemplates()[$template])) {
            header('Location: /admin/client-setup?template_error=1');
            exit;
        }

        $this->writeClientSetupSnapshot('before_template_' . $template);
        $this->applyTemplateValues($template, true);
        header('Location: /admin/client-setup?saved=template');
        exit;
    }

    public function runClientPreflight(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        Settings::set('client_preflight_checked_at', date('Y-m-d H:i:s'));
        Settings::set('client_preflight_result', $this->hasBlockingProductionIssue() ? 'ng' : 'ok');
        header('Location: /admin/client-setup?saved=preflight');
        exit;
    }

    public function applyClientSetupDefaults(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        $baseUrl = trim((string)Settings::get('public_base_url', ''));
        if ($baseUrl === '') {
            $baseUrl = $this->currentBaseUrl();
        }
        $baseUrl = rtrim($baseUrl, '/');

        $defaults = [
            'service_name' => 'AIアート教室',
            'classroom_name' => 'AIアート教室',
            'workflow_template' => 'ai_art_class_standard',
            'workflow_approval_mode' => 'manual',
            'workflow_payment_mode' => 'free_or_paid_by_class',
            'workflow_day_notice' => 'day_of',
            'workflow_day_notice_mode' => 'day_of',
            'workflow_attendance_gate' => 'approved_and_time_window',
            'image_engine' => 'openai',
            'image_human_safe_engine' => 'openai',
            'image_high_quality_engine' => 'openai',
            'image_quality_level' => 'premium',
            'openai_image_quality' => 'high',
            'stability_auto_switch_enabled' => '1',
            'stability_auto_switch_threshold' => '1',
            'stability_fallback_engine' => 'openai',
            'max_daily_requests_per_user' => '2',
            'max_images_per_request' => '8',
            'images_per_pattern' => '4',
            'line_monthly_limit' => '5000',
            'ticket_valid_days' => '180',
        ];

        if ($baseUrl !== '') {
            $defaults['public_base_url'] = $baseUrl;
            $defaults['terms_url'] = $baseUrl . '/terms';
            $defaults['privacy_url'] = $baseUrl . '/privacy';
        }

        foreach ($defaults as $key => $value) {
            if (!$this->settingFilled($key)) {
                Settings::set($key, $value);
            }
        }

        foreach ([
            'workflow_first_visit_free',
            'workflow_ticket_enabled',
            'workflow_subscription_enabled',
            'workflow_cash_payment_enabled',
        ] as $key) {
            Settings::set($key, '1');
        }

        header('Location: /admin/client-setup?saved=defaults');
        exit;
    }

    public function exportClientSetup(): void {
        $settings = Settings::all();
        $data = [
            'format' => 'aiart_client_setup',
            'version' => APP_VERSION,
            'exported_at' => date('c'),
            'includes_secrets' => false,
            'settings' => [],
        ];

        foreach ($this->clientPortableSettingKeys() as $key) {
            if (array_key_exists($key, $settings)) {
                $data['settings'][$key] = (string)$settings[$key];
            }
        }

        $client = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)($data['settings']['client_name'] ?? 'client'));
        $client = trim($client, '-') ?: 'client';
        $filename = 'aiart-client-setup-' . $client . '-' . date('Ymd-His') . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public function exportClientChecklist(): void {
        $settings = Settings::all();
        $clientName = trim((string)($settings['client_name'] ?? $settings['client_company_name'] ?? 'client'));
        $safeClient = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $clientName);
        $safeClient = trim((string)$safeClient, '-') ?: 'client';
        $baseUrl = trim((string)($settings['public_base_url'] ?? '')) ?: $this->currentBaseUrl();

        $lines = [];
        $lines[] = '# AIアート教室 横展開 検収チェックリスト';
        $lines[] = '';
        $lines[] = '- 作成日時: ' . date('Y-m-d H:i:s');
        $lines[] = '- システムバージョン: ' . APP_VERSION;
        $lines[] = '- クライアント名: ' . ($clientName !== '' ? $clientName : '未設定');
        $lines[] = '- サービス名: ' . (trim((string)($settings['service_name'] ?? '')) ?: '未設定');
        $lines[] = '- 教室名: ' . (trim((string)($settings['classroom_name'] ?? '')) ?: '未設定');
        $lines[] = '- 公開URL: ' . ($baseUrl !== '' ? $baseUrl : '未設定');
        $lines[] = '';

        $lines[] = '## 初期設定チェック';
        foreach ($this->groupChecks($this->clientSetupChecks()) as $group => $items) {
            $lines[] = '';
            $lines[] = '### ' . $group;
            foreach ($items as $item) {
                $ok = !empty($item['ok']);
                $lines[] = '- ' . ($ok ? '[x] ' : '[ ] ') . $item['label'] . ' - ' . ($ok ? '設定済み' : '未設定');
                if (!$ok) {
                    $lines[] = '  - 対応: ' . $item['fix'];
                }
            }
        }

        $lines[] = '';
        $lines[] = '## 公開前ヘルスチェック';
        foreach ($this->groupChecks($this->clientRolloutHealthChecks()) as $group => $items) {
            $lines[] = '';
            $lines[] = '### ' . $group;
            foreach ($items as $item) {
                $ok = !empty($item['ok']);
                $level = (string)($item['level'] ?? '確認');
                $lines[] = '- ' . ($ok ? '[x] ' : '[ ] ') . '【' . $level . '】' . $item['label'] . ' - ' . ($ok ? 'OK' : '要対応');
                if (!$ok) {
                    $lines[] = '  - 対応: ' . $item['fix'];
                }
            }
        }

        $lines[] = '';
        $lines[] = '## 動作確認';
        foreach ([
            '友だち追加QRからLINE登録できる',
            '予約LIFFで予約できる',
            '管理画面で承認できる',
            '当日参加時間内だけ画像生成できる',
            'Stripe決済とWebhook反映ができる',
            '回数券、サブスク、初回無料、現金支払いの扱いを確認した',
            '残高不足時にフォールバックAIへ切り替わる',
            'オーナー、管理者、スタッフの表示権限が分離されている',
        ] as $item) {
            $lines[] = '- [ ] ' . $item;
        }

        $lines[] = '';
        $lines[] = '## 横展開で上書きしないもの';
        foreach (['config/db.php', 'config/installed.lock', 'storage/', 'uploads/'] as $item) {
            $lines[] = '- ' . $item;
        }

        $filename = 'aiart-rollout-checklist-' . $safeClient . '-' . date('Ymd-His') . '.md';
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo implode("\n", $lines) . "\n";
        exit;
    }

    public function exportClientHandover(): void {
        $settings = Settings::all();
        $clientName = trim((string)($settings['client_name'] ?? $settings['client_company_name'] ?? 'client'));
        $safeClient = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $clientName);
        $safeClient = trim((string)$safeClient, '-') ?: 'client';
        $baseUrl = trim((string)($settings['public_base_url'] ?? '')) ?: $this->currentBaseUrl();

        $value = static function (array $settings, string $key, string $default = '未設定'): string {
            $raw = trim((string)($settings[$key] ?? ''));
            return $raw !== '' ? $raw : $default;
        };
        $yesNo = static function (array $settings, string $key): string {
            return ((string)($settings[$key] ?? '0') === '1') ? '有効' : '無効';
        };

        $lines = [];
        $lines[] = '# AIアート教室 横展開 引き継ぎメモ';
        $lines[] = '';
        $lines[] = '- 作成日時: ' . date('Y-m-d H:i:s');
        $lines[] = '- システムバージョン: ' . APP_VERSION;
        $lines[] = '- クライアント名: ' . ($clientName !== '' ? $clientName : '未設定');
        $lines[] = '- 公開URL: ' . ($baseUrl !== '' ? $baseUrl : '未設定');
        $lines[] = '- 注意: APIキー、Webhook署名シークレット、DB情報、個人情報、画像データはこのメモに含めません。';
        $lines[] = '';

        $lines[] = '## 基本情報';
        foreach ([
            'サービス名' => 'service_name',
            '教室名' => 'classroom_name',
            '会社名' => 'client_company_name',
            '所在地' => 'client_company_address',
            '電話番号' => 'client_contact_phone',
            '問い合わせメール' => 'client_contact_email',
        ] as $label => $key) {
            $lines[] = '- ' . $label . ': ' . $value($settings, $key);
        }
        $lines[] = '';

        $lines[] = '## 運用フロー';
        foreach ([
            '運用テンプレート' => 'workflow_template',
            '承認方式' => 'workflow_approval_mode',
            '支払い方式' => 'workflow_payment_mode',
            '当日案内' => 'workflow_day_notice_mode',
            '参加条件' => 'workflow_attendance_gate',
        ] as $label => $key) {
            $lines[] = '- ' . $label . ': ' . $value($settings, $key);
        }
        foreach ([
            '自動案内' => 'workflow_auto_notice_enabled',
            '初回無料' => 'workflow_first_visit_free',
            '回数券' => 'workflow_ticket_enabled',
            'サブスク' => 'workflow_subscription_enabled',
            '現金支払い' => 'workflow_cash_payment_enabled',
        ] as $label => $key) {
            $lines[] = '- ' . $label . ': ' . $yesNo($settings, $key);
        }
        $lines[] = '';

        $lines[] = '## 外部サービス設定の確認';
        foreach ($this->clientSetupChecks() as $item) {
            if (!in_array((string)($item['group'] ?? ''), ['LINE', 'Stripe', 'AI', '通知'], true)) {
                continue;
            }
            $ok = !empty($item['ok']);
            $lines[] = '- ' . ($ok ? '[x] ' : '[ ] ') . ($item['group'] ?? '設定') . ' / ' . ($item['label'] ?? '') . ' - ' . ($ok ? '設定済み' : '未設定');
            if (!$ok) {
                $lines[] = '  - 対応: ' . ($item['fix'] ?? '');
            }
        }
        $lines[] = '';

        $lines[] = '## 納品時に確認するURL';
        foreach ([
            '管理画面' => '/admin/login',
            '予約カレンダー' => '/liff/calendar',
            '購入・会員メニュー' => '/liff/shop',
            'ガチャ' => '/liff/gacha',
            '特定商取引法に基づく表記' => '/commerce',
            'プライバシーポリシー' => '/privacy',
            '利用規約' => '/terms',
            'Stripe Webhook' => '/stripe/webhook',
        ] as $label => $path) {
            $url = $baseUrl !== '' ? rtrim($baseUrl, '/') . $path : $path;
            $lines[] = '- ' . $label . ': ' . $url;
        }
        $lines[] = '';

        $lines[] = '## クライアント側で所有・管理するもの';
        foreach ([
            'LINE公式アカウント',
            'LINE Developers チャネル、LIFF ID、Webhook設定',
            'Stripeアカウント、商品、Price ID、Webhook署名シークレット',
            '画像生成AIのAPIキーと利用料',
            'ドメイン、サーバー、DB、メール送信設定',
        ] as $item) {
            $lines[] = '- ' . $item;
        }
        $lines[] = '';

        $lines[] = '## アップデート時に上書きしないもの';
        foreach (['config/db.php', 'config/installed.lock', 'storage/', 'uploads/'] as $item) {
            $lines[] = '- ' . $item;
        }
        $lines[] = '';

        $lines[] = '## 残作業メモ';
        foreach ([
            'LINE友だち追加、予約、承認、参加確認の実機テスト',
            'Stripeの本番決済、Webhook、返金・キャンセルの確認',
            'AI生成の品質、残高不足時のフォールバック確認',
            'オーナー、管理者、スタッフ権限での表示確認',
            '公開ページの会社情報、規約、プライバシーポリシー、特商法の確認',
        ] as $item) {
            $lines[] = '- [ ] ' . $item;
        }

        $filename = 'aiart-rollout-handover-' . $safeClient . '-' . date('Ymd-His') . '.md';
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo implode("\n", $lines) . "\n";
        exit;
    }

    public function exportClientGuide(): void {
        $settings = Settings::all();
        $clientName = trim((string)($settings['client_name'] ?? $settings['client_company_name'] ?? 'client'));
        $safeClient = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $clientName);
        $safeClient = trim((string)$safeClient, '-') ?: 'client';
        $baseUrl = trim((string)($settings['public_base_url'] ?? '')) ?: $this->currentBaseUrl();

        $lines = [];
        $lines[] = '# ' . ($clientName !== '' ? $clientName : '新規クライアント') . ' 導入手順書';
        $lines[] = '';
        $lines[] = '- 作成日時: ' . date('Y-m-d H:i:s');
        $lines[] = '- システムバージョン: ' . APP_VERSION;
        $lines[] = '- 公開URL: ' . ($baseUrl !== '' ? $baseUrl : '未設定');
        $lines[] = '';
        $lines[] = '## 1. サーバー設置';
        $lines[] = '- 新規ドメインまたはサブドメインを用意します。';
        $lines[] = '- 新規DBを作成し、`config/db.php` を案件ごとに設定します。';
        $lines[] = '- `config/installed.lock`、`storage/`、`uploads/` は案件ごとに分離します。';
        $lines[] = '- 共通アップデートZIPを適用しても、上記の案件別データは上書きしません。';
        $lines[] = '';
        $lines[] = '## 2. LINE Developers';
        $lines[] = '- Messaging APIチャネルを作成します。';
        $lines[] = '- Webhook URLを `' . ($baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://example.com') . '/line/webhook` に設定します。';
        $lines[] = '- 予約用LIFFと購入用LIFFを別々に作成します。';
        $lines[] = '- LIFF URLは予約 `/liff/calendar`、購入 `/liff/shop` を設定します。';
        $lines[] = '- 一般参加者に案内する前に、LINE Login / LIFFチャネルを公開状態にします。';
        $lines[] = '';
        $lines[] = '## 3. LINE公式アカウント';
        $lines[] = '- 友だち追加QRを確認します。';
        $lines[] = '- リッチメニューを初回、参加済み、サブスク、回数券の区分に合わせて設定します。';
        $lines[] = '- メッセージ上限を契約プランに合わせて設定します。';
        $lines[] = '';
        $lines[] = '## 4. Stripe';
        $lines[] = '- 月額、年額、回数券、一回払いの商品とPriceを作成します。';
        $lines[] = '- API設定に `price_` で始まるPrice IDを入力します。`prod_` IDでは決済ページを作成できません。';
        $lines[] = '- Webhook URLを `' . ($baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://example.com') . '/stripe/webhook` に設定します。';
        $lines[] = '- Webhookイベントは `checkout.session.completed`、`invoice.payment_succeeded`、`customer.subscription.deleted` を含めます。';
        $lines[] = '- 本番公開前に、テストキーから本番キーへ切り替えます。';
        $lines[] = '';
        $lines[] = '## 5. AI API';
        $lines[] = '- OpenAI、Stability、Grokなど利用するAPIキーをクライアント所有で設定します。';
        $lines[] = '- 残高不足時のフォールバックAIを設定します。';
        $lines[] = '- 顔、手、人体崩れを避ける品質設定を確認します。';
        $lines[] = '';
        $lines[] = '## 6. 公開前確認';
        foreach ($this->clientProductionChecks() as $check) {
            $lines[] = '- ' . (!empty($check['ok']) ? '[x] ' : '[ ] ') . ($check['label'] ?? '') . ' - ' . (!empty($check['ok']) ? 'OK' : ($check['fix'] ?? '要確認'));
        }

        $filename = 'aiart-rollout-guide-' . $safeClient . '-' . date('Ymd-His') . '.md';
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo implode("\n", $lines) . "\n";
        exit;
    }

    public function importClientSetup(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        if (empty($_FILES['client_setup_json']['tmp_name']) || !is_uploaded_file($_FILES['client_setup_json']['tmp_name'])) {
            header('Location: /admin/client-setup?import_error=file');
            exit;
        }

        $raw = (string)file_get_contents($_FILES['client_setup_json']['tmp_name']);
        $json = json_decode($raw, true);
        if (!is_array($json) || ($json['format'] ?? '') !== 'aiart_client_setup' || !isset($json['settings']) || !is_array($json['settings'])) {
            header('Location: /admin/client-setup?import_error=format');
            exit;
        }

        $this->writeClientSetupSnapshot('before_import');
        $allowed = array_flip($this->clientPortableSettingKeys());
        $count = 0;
        foreach ($json['settings'] as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            Settings::set((string)$key, trim((string)$value));
            $count++;
        }

        header('Location: /admin/client-setup?saved=imported&count=' . $count);
        exit;
    }

    public function createClientSetupSnapshot(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $this->writeClientSetupSnapshot('manual');
        header('Location: /admin/client-setup?saved=snapshot');
        exit;
    }

    public function restoreClientSetupSnapshot(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        $name = basename((string)($_POST['snapshot'] ?? ''));
        if ($name === '' || !preg_match('/^client_setup_[a-z0-9_-]+_\d{8}_\d{6}\.json$/i', $name)) {
            header('Location: /admin/client-setup?restore_error=file');
            exit;
        }

        $path = $this->clientSetupSnapshotDir() . '/' . $name;
        if (!is_file($path)) {
            header('Location: /admin/client-setup?restore_error=file');
            exit;
        }

        $json = json_decode((string)file_get_contents($path), true);
        if (!is_array($json) || ($json['format'] ?? '') !== 'aiart_client_setup_snapshot' || !isset($json['settings']) || !is_array($json['settings'])) {
            header('Location: /admin/client-setup?restore_error=format');
            exit;
        }

        $this->writeClientSetupSnapshot('before_restore');
        $allowed = array_flip($this->clientPortableSettingKeys());
        $count = 0;
        foreach ($json['settings'] as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            Settings::set((string)$key, trim((string)$value));
            $count++;
        }

        header('Location: /admin/client-setup?saved=restored&count=' . $count);
        exit;
    }

    public function restoreClientSetupPartial(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        $name = basename((string)($_POST['snapshot'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        if ($name === '' || !preg_match('/^client_setup_[a-z0-9_-]+_\d{8}_\d{6}\.json$/i', $name)) {
            header('Location: /admin/client-setup?restore_error=file');
            exit;
        }

        $path = $this->clientSetupSnapshotDir() . '/' . $name;
        $json = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($json) || ($json['format'] ?? '') !== 'aiart_client_setup_snapshot' || !isset($json['settings']) || !is_array($json['settings'])) {
            header('Location: /admin/client-setup?restore_error=format');
            exit;
        }

        $keys = $this->clientPartialRestoreKeys($category);
        if (!$keys) {
            header('Location: /admin/client-setup?restore_error=category');
            exit;
        }

        $this->writeClientSetupSnapshot('before_partial_restore_' . $category);
        $count = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $json['settings'])) {
                Settings::set($key, trim((string)$json['settings'][$key]));
                $count++;
            }
        }

        header('Location: /admin/client-setup?saved=partial_restored&count=' . $count);
        exit;
    }

    private function writeClientSetupSnapshot(string $reason): string {
        $dir = $this->clientSetupSnapshotDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Failed to create client setup snapshot directory.');
        }

        $settings = Settings::all();
        $data = [
            'format' => 'aiart_client_setup_snapshot',
            'version' => APP_VERSION,
            'reason' => $reason,
            'created_at' => date('c'),
            'includes_secrets' => false,
            'settings' => [],
        ];
        foreach ($this->clientPortableSettingKeys() as $key) {
            if (array_key_exists($key, $settings)) {
                $data['settings'][$key] = (string)$settings[$key];
            }
        }

        $name = 'client_setup_' . preg_replace('/[^a-z0-9_-]+/i', '-', $reason) . '_' . date('Ymd_His') . '.json';
        $path = $dir . '/' . $name;
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $path;
    }

    private function clientSetupSnapshots(int $limit = 8): array {
        $dir = $this->clientSetupSnapshotDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/client_setup_*.json') ?: [];
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $items = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $json = json_decode((string)file_get_contents($file), true);
            $items[] = [
                'name' => basename($file),
                'created_at' => is_array($json) ? (string)($json['created_at'] ?? '') : '',
                'reason' => is_array($json) ? (string)($json['reason'] ?? '') : '',
                'count' => is_array($json) && isset($json['settings']) && is_array($json['settings']) ? count($json['settings']) : 0,
                'mtime' => filemtime($file),
            ];
        }
        return $items;
    }

    private function clientSetupSnapshotDir(): string {
        return STORAGE_PATH . '/client_setup_snapshots';
    }

    private function clientPortableSettingKeys(): array {
        return [
            'client_name',
            'service_name',
            'classroom_name',
            'service_tagline',
            'public_base_url',
            'client_contact_email',
            'client_contact_phone',
            'client_postal_code',
            'client_address',
            'client_company_name',
            'client_operator_name',
            'client_invoice_name',
            'client_memo',
            'terms_url',
            'privacy_url',
            'workflow_template',
            'workflow_approval_mode',
            'workflow_payment_mode',
            'workflow_day_notice',
            'workflow_day_notice_mode',
            'workflow_auto_notice_enabled',
            'workflow_attendance_gate',
            'workflow_first_visit_free',
            'workflow_ticket_enabled',
            'workflow_subscription_enabled',
            'workflow_cash_payment_enabled',
            'generation_access_mode',
            'generation_window_start',
            'generation_window_end',
            'generation_window_message',
            'generation_online_enabled',
            'generation_available_date_start',
            'generation_available_date_end',
            'generation_available_weekdays',
            'generation_period_request_limit',
            'subscription_price_label',
            'annual_subscription_price_label',
            'ticket_valid_days',
            'ticket_plans',
            'image_engine',
            'image_human_safe_engine',
            'image_high_quality_engine',
            'image_quality_level',
            'openai_image_quality',
            'stability_auto_switch_enabled',
            'stability_auto_switch_threshold',
            'stability_fallback_engine',
            'max_daily_requests_per_user',
            'max_images_per_request',
            'images_per_pattern',
            'line_monthly_limit',
            'ng_words',
            'admin_notify_email',
            'mail_from',
        ];
    }

    private function clientSetupChecks(): array {
        $baseUrl = $this->settingFilled('public_base_url') ? Settings::get('public_base_url', '') : $this->currentBaseUrl();
        return [
            ['group' => '基本情報', 'label' => 'クライアント名', 'ok' => $this->settingFilled('client_name') || $this->settingFilled('client_company_name'), 'fix' => 'この画面でクライアント名または会社名を入力してください。'],
            ['group' => '基本情報', 'label' => '公開URL', 'ok' => $baseUrl !== '', 'fix' => '公開ドメインを public_base_url に入力してください。'],
            ['group' => '公開ページ', 'label' => '問い合わせメール', 'ok' => $this->settingFilled('client_contact_email') || $this->settingFilled('admin_email'), 'fix' => '公開ページ設定またはこの画面で問い合わせメールを入力してください。'],
            ['group' => 'LINE', 'label' => 'Messaging API', 'ok' => $this->settingFilled('line_channel_secret') && $this->settingFilled('line_channel_access_token'), 'fix' => 'API設定で LINE Channel Secret / Access Token を入力してください。'],
            ['group' => 'LINE', 'label' => 'LIFF', 'ok' => $this->settingFilled('liff_id') || $this->settingFilled('shop_liff_id'), 'fix' => 'LINE設定で予約用/購入用 LIFF ID を入力してください。'],
            ['group' => 'Stripe', 'label' => 'Stripeキー', 'ok' => $this->settingFilled('stripe_secret_key') && $this->settingFilled('stripe_publishable_key'), 'fix' => 'API設定で Stripe シークレットキーと公開可能キーを入力してください。'],
            ['group' => 'Stripe', 'label' => 'Webhook署名', 'ok' => $this->settingFilled('stripe_webhook_secret'), 'fix' => 'Stripe Webhook を作成後、署名シークレットを入力してください。'],
            ['group' => '料金', 'label' => '料金プラン', 'ok' => $this->settingFilled('stripe_subscription_price_id') || $this->settingFilled('stripe_annual_subscription_price_id') || $this->settingFilled('ticket_plans'), 'fix' => 'API設定で月額、年額、回数券のいずれかを設定してください。'],
            ['group' => 'AI', 'label' => '画像生成API', 'ok' => $this->settingFilled('openai_api_key') || $this->settingFilled('stability_api_key') || $this->settingFilled('grok_api_key'), 'fix' => 'API設定で OpenAI / Stability / Grok のいずれかを設定してください。'],
            ['group' => '通知', 'label' => '管理者通知', 'ok' => $this->settingFilled('admin_line_user_id') || $this->settingFilled('admin_email'), 'fix' => 'API設定で管理者LINEユーザーIDまたはメールを入力してください。'],
            ['group' => '運用', 'label' => 'フロー設定', 'ok' => $this->settingFilled('workflow_template'), 'fix' => 'この画面で運用テンプレートを保存してください。'],
            ['group' => '運用', 'label' => '生成受付方式', 'ok' => in_array(Settings::get('generation_access_mode', 'class_attendance'), ['class_attendance', 'time_window_only', 'class_or_time_window', 'always_open'], true), 'fix' => '教室参加型、受付時間型、常時受付のいずれかを選択してください。'],
            ['group' => '運用', 'label' => 'オンライン生成', 'ok' => Settings::get('generation_online_enabled', '1') === '1' || Settings::get('generation_access_mode', 'class_attendance') === 'class_attendance', 'fix' => '予約なしで生成させる場合は、オンライン生成を有効にしてください。'],
        ];
    }

    private function groupChecks(array $checks): array {
        $groups = [];
        foreach ($checks as $check) {
            $group = (string)($check['group'] ?? 'その他');
            $groups[$group][] = $check;
        }
        return $groups;
    }

    private function clientRolloutHealthChecks(): array {
        $baseUrl = $this->settingFilled('public_base_url') ? trim((string)Settings::get('public_base_url', '')) : $this->currentBaseUrl();
        $lineLimit = (int)Settings::get('line_monthly_limit', '0');
        $fallback = strtolower(trim((string)Settings::get('stability_fallback_engine', 'openai')));
        $ticketPlans = trim((string)Settings::get('ticket_plans', ''));
        $hasTicketPlan = false;
        if ($ticketPlans !== '') {
            $decoded = json_decode($ticketPlans, true);
            $hasTicketPlan = is_array($decoded) && count($decoded) > 0;
        }

        return [
            ['group' => '公開前チェック', 'level' => '必須', 'label' => '公開URLがHTTPS', 'ok' => $baseUrl !== '' && strpos($baseUrl, 'https://') === 0, 'fix' => '公開URLは https:// から始まるURLで設定してください。'],
            ['group' => '公開前チェック', 'level' => '必須', 'label' => '公開ページ情報', 'ok' => $this->settingFilled('client_company_name') && ($this->settingFilled('client_contact_email') || $this->settingFilled('admin_email')), 'fix' => '会社名、問い合わせメール、所在地などを公開ページ設定またはこの画面で入力してください。'],
            ['group' => 'LINE', 'level' => '必須', 'label' => '予約用/購入用LIFF', 'ok' => $this->settingFilled('liff_id') && $this->settingFilled('shop_liff_id'), 'fix' => 'LINE設定で予約用LIFF IDと購入専用LIFF IDを両方設定してください。'],
            ['group' => 'LINE', 'level' => '推奨', 'label' => 'LINE送信上限', 'ok' => $lineLimit >= 5000, 'fix' => 'LINEプロプランの場合は、月間送信上限を5000通以上に設定してください。'],
            ['group' => 'Stripe', 'level' => '必須', 'label' => '決済キーとWebhook', 'ok' => $this->settingFilled('stripe_secret_key') && $this->settingFilled('stripe_publishable_key') && $this->settingFilled('stripe_webhook_secret'), 'fix' => 'Stripeのシークレットキー、公開可能キー、Webhook署名シークレットを設定してください。'],
            ['group' => 'Stripe', 'level' => '必須', 'label' => '販売プラン', 'ok' => $this->settingFilled('stripe_subscription_price_id') || $this->settingFilled('stripe_annual_subscription_price_id') || $hasTicketPlan || $this->settingFilled('one_time_price_id'), 'fix' => '月額、年額、回数券、一回払いのいずれかを設定してください。'],
            ['group' => 'AI', 'level' => '必須', 'label' => '画像生成API', 'ok' => $this->engineAvailable('openai') || $this->engineAvailable('stability') || $this->engineAvailable('grok'), 'fix' => 'OpenAI、Stability、GrokのいずれかのAPIキーを設定してください。'],
            ['group' => 'AI', 'level' => '推奨', 'label' => '残高切れ時の自動切替', 'ok' => Settings::get('stability_auto_switch_enabled', '1') !== '0' && $this->engineAvailable($fallback), 'fix' => 'Stability残高切れに備え、フォールバック先とそのAPIキーを設定してください。'],
            ['group' => 'AI', 'level' => '推奨', 'label' => '顔・手崩れ対策', 'ok' => $this->settingFilled('ng_words') && (int)Settings::get('images_per_pattern', '4') <= 4, 'fix' => '禁止ワード/品質ガードを設定し、1パターンあたりの生成枚数は4枚以下を推奨します。'],
            ['group' => '運用', 'level' => '必須', 'label' => '当日参加条件', 'ok' => Settings::get('workflow_attendance_gate', '') === 'approved_and_time_window' || Settings::get('workflow_attendance_gate', '') === 'paid_or_free_and_time_window', 'fix' => '参加承認だけで生成できないよう、参加時間内の条件を設定してください。'],
            ['group' => '運用', 'level' => '推奨', 'label' => '生成受付時間', 'ok' => Settings::get('generation_access_mode', 'class_attendance') !== 'time_window_only' || ($this->settingFilled('generation_window_start') && $this->settingFilled('generation_window_end')), 'fix' => '予約なし運用の場合は、生成申請を受け付ける開始時刻と終了時刻を設定してください。'],
            ['group' => '運用', 'level' => '推奨', 'label' => '生成可能日・生成数', 'ok' => true, 'fix' => 'オンライン生成を使う場合は、生成可能開始日・終了日・曜日・期間内上限を必要に応じて設定してください。'],
            ['group' => '運用', 'level' => '推奨', 'label' => '設定スナップショット', 'ok' => count($this->clientSetupSnapshots(1)) > 0, 'fix' => '導入前・大きな設定変更前に、この画面で現在の設定を保存してください。'],
        ];
    }

    private function clientTemplates(): array {
        return [
            'ai_art_class_standard' => [
                'label' => 'AIアート教室 標準',
                'description' => '予約、承認、当日参加、画像生成、回数券・サブスク販売を使う標準構成です。',
                'settings' => [
                    'workflow_template' => 'ai_art_class_standard',
                    'workflow_approval_mode' => 'manual',
                    'workflow_payment_mode' => 'free_or_paid_by_class',
                    'workflow_day_notice_mode' => 'day_of',
                    'workflow_attendance_gate' => 'approved_and_time_window',
                    'workflow_auto_notice_enabled' => '1',
                    'workflow_first_visit_free' => '1',
                    'workflow_ticket_enabled' => '1',
                    'workflow_subscription_enabled' => '1',
                    'workflow_cash_payment_enabled' => '1',
                    'generation_access_mode' => 'class_attendance',
                    'generation_window_start' => '',
                    'generation_window_end' => '',
                    'generation_online_enabled' => '1',
                    'generation_available_date_start' => '',
                    'generation_available_date_end' => '',
                    'generation_available_weekdays' => '',
                    'generation_period_request_limit' => '0',
                    'max_daily_requests_per_user' => '2',
                    'max_images_per_request' => '8',
                    'images_per_pattern' => '4',
                ],
            ],
            'workshop_event' => [
                'label' => '単発ワークショップ',
                'description' => '単発開催、手動承認、当日参加、必要に応じて一回払いを使う構成です。',
                'settings' => [
                    'workflow_template' => 'workshop_event',
                    'workflow_approval_mode' => 'manual',
                    'workflow_payment_mode' => 'free_or_paid_by_class',
                    'workflow_day_notice_mode' => 'day_of',
                    'workflow_attendance_gate' => 'approved_and_time_window',
                    'workflow_auto_notice_enabled' => '1',
                    'workflow_first_visit_free' => '0',
                    'workflow_ticket_enabled' => '0',
                    'workflow_subscription_enabled' => '0',
                    'workflow_cash_payment_enabled' => '1',
                    'generation_access_mode' => 'class_attendance',
                    'generation_window_start' => '',
                    'generation_window_end' => '',
                    'generation_online_enabled' => '1',
                    'generation_available_date_start' => '',
                    'generation_available_date_end' => '',
                    'generation_available_weekdays' => '',
                    'generation_period_request_limit' => '0',
                    'max_daily_requests_per_user' => '1',
                    'max_images_per_request' => '4',
                    'images_per_pattern' => '2',
                ],
            ],
            'membership_school' => [
                'label' => '月額会員型',
                'description' => '月額・年額サブスクを中心に、会員判定で予約や参加を制御する構成です。',
                'settings' => [
                    'workflow_template' => 'membership_school',
                    'workflow_approval_mode' => 'paid_auto',
                    'workflow_payment_mode' => 'ticket_or_subscription',
                    'workflow_day_notice_mode' => 'day_of',
                    'workflow_attendance_gate' => 'paid_or_free_and_time_window',
                    'workflow_auto_notice_enabled' => '1',
                    'workflow_first_visit_free' => '1',
                    'workflow_ticket_enabled' => '1',
                    'workflow_subscription_enabled' => '1',
                    'workflow_cash_payment_enabled' => '0',
                    'generation_access_mode' => 'class_attendance',
                    'generation_window_start' => '',
                    'generation_window_end' => '',
                    'generation_online_enabled' => '1',
                    'generation_available_date_start' => '',
                    'generation_available_date_end' => '',
                    'generation_available_weekdays' => '',
                    'generation_period_request_limit' => '0',
                    'max_daily_requests_per_user' => '2',
                    'max_images_per_request' => '8',
                    'images_per_pattern' => '4',
                ],
            ],
        ];
    }

    private function applyTemplateValues(string $template, bool $overwrite): void {
        $templates = $this->clientTemplates();
        if (!isset($templates[$template])) {
            return;
        }

        foreach ($templates[$template]['settings'] as $key => $value) {
            if ($overwrite || !$this->settingFilled($key)) {
                Settings::set($key, (string)$value);
            }
        }
    }

    private function clientPreflightChecks(): array {
        $baseUrl = trim((string)Settings::get('public_base_url', '')) ?: $this->currentBaseUrl();
        $baseUrl = rtrim($baseUrl, '/');

        $urls = [
            ['group' => '公開URL', 'label' => '公開URL', 'url' => $baseUrl],
            ['group' => '公開ページ', 'label' => '利用規約', 'url' => $baseUrl ? $baseUrl . '/terms' : ''],
            ['group' => '公開ページ', 'label' => 'プライバシーポリシー', 'url' => $baseUrl ? $baseUrl . '/privacy' : ''],
            ['group' => '公開ページ', 'label' => '特商法ページ', 'url' => $baseUrl ? $baseUrl . '/commerce' : ''],
            ['group' => 'Webhook', 'label' => 'Stripe Webhook URL', 'url' => $baseUrl ? $baseUrl . '/stripe/webhook' : ''],
        ];

        $checks = [];
        foreach ($urls as $item) {
            $checks[] = [
                'group' => $item['group'],
                'label' => $item['label'],
                'ok' => $item['url'] !== '' && strpos($item['url'], 'https://') === 0,
                'fix' => '公開URLを https:// で設定してください。',
                'detail' => $item['url'] ?: '未設定',
            ];
        }

        $checks[] = ['group' => 'LINE', 'label' => 'LINE APIキー', 'ok' => $this->settingFilled('line_channel_secret') && $this->settingFilled('line_channel_access_token'), 'fix' => 'LINE Channel Secret / Access Tokenを設定してください。', 'detail' => 'API設定'];
        $checks[] = ['group' => 'LIFF', 'label' => '予約・購入LIFF', 'ok' => $this->settingFilled('liff_id') && $this->settingFilled('shop_liff_id'), 'fix' => '予約用LIFF IDと購入用LIFF IDを設定してください。', 'detail' => 'LINE設定'];
        $checks[] = ['group' => 'Stripe', 'label' => 'Stripeキー', 'ok' => $this->settingFilled('stripe_secret_key') && $this->settingFilled('stripe_publishable_key') && $this->settingFilled('stripe_webhook_secret'), 'fix' => 'StripeキーとWebhook署名を設定してください。', 'detail' => 'API設定'];
        $checks[] = ['group' => 'AI', 'label' => '画像生成AI', 'ok' => $this->engineAvailable('openai') || $this->engineAvailable('stability') || $this->engineAvailable('grok'), 'fix' => '利用する画像生成AIのAPIキーを設定してください。', 'detail' => 'API設定'];
        return $checks;
    }

    private function clientProductionChecks(): array {
        $secret = trim((string)Settings::get('stripe_secret_key', ''));
        $publishable = trim((string)Settings::get('stripe_publishable_key', ''));
        $baseUrl = trim((string)Settings::get('public_base_url', '')) ?: $this->currentBaseUrl();

        $checks = $this->clientRolloutHealthChecks();
        $checks[] = ['group' => '本番切替', 'level' => '必須', 'label' => 'Stripe本番キー', 'ok' => $secret === '' || strpos($secret, 'sk_live_') === 0, 'fix' => '本番運用では sk_live_ で始まるシークレットキーを設定してください。'];
        $checks[] = ['group' => '本番切替', 'level' => '必須', 'label' => 'Stripe公開可能キー', 'ok' => $publishable === '' || strpos($publishable, 'pk_live_') === 0, 'fix' => '本番運用では pk_live_ で始まる公開可能キーを設定してください。'];
        $checks[] = ['group' => '本番切替', 'level' => '必須', 'label' => '公開URLが本番HTTPS', 'ok' => $baseUrl !== '' && strpos($baseUrl, 'https://') === 0 && strpos($baseUrl, 'localhost') === false, 'fix' => '本番のHTTPSドメインを設定してください。'];
        return $checks;
    }

    private function hasBlockingProductionIssue(): bool {
        foreach ($this->clientProductionChecks() as $check) {
            if (($check['level'] ?? '') === '必須' && empty($check['ok'])) {
                return true;
            }
        }
        return false;
    }

    private function clientPartialRestoreKeys(string $category): array {
        $sets = [
            'basic' => ['client_name', 'service_name', 'classroom_name', 'service_tagline', 'public_base_url'],
            'public' => ['client_contact_email', 'client_contact_phone', 'client_postal_code', 'client_address', 'client_company_name', 'client_operator_name', 'client_invoice_name', 'client_memo', 'terms_url', 'privacy_url'],
            'workflow' => ['workflow_template', 'workflow_approval_mode', 'workflow_payment_mode', 'workflow_day_notice', 'workflow_day_notice_mode', 'workflow_auto_notice_enabled', 'workflow_attendance_gate', 'workflow_first_visit_free', 'workflow_ticket_enabled', 'workflow_subscription_enabled', 'workflow_cash_payment_enabled', 'generation_access_mode', 'generation_window_start', 'generation_window_end', 'generation_window_message', 'generation_online_enabled', 'generation_available_date_start', 'generation_available_date_end', 'generation_available_weekdays', 'generation_period_request_limit'],
            'pricing' => ['subscription_price_label', 'annual_subscription_price_label', 'ticket_valid_days', 'ticket_plans'],
            'ai' => ['image_engine', 'image_human_safe_engine', 'image_high_quality_engine', 'image_quality_level', 'openai_image_quality', 'stability_auto_switch_enabled', 'stability_auto_switch_threshold', 'stability_fallback_engine', 'max_daily_requests_per_user', 'max_images_per_request', 'images_per_pattern', 'ng_words'],
        ];
        return $sets[$category] ?? [];
    }

    private function workflowSettings(): array {
        return [
            'templates' => [
                'ai_art_class_standard' => 'AIアート教室 標準',
                'workshop_event' => '単発ワークショップ',
                'membership_school' => '月額会員型',
                'custom' => '個別カスタム',
            ],
            'approval_modes' => [
                'manual' => '手動承認',
                'auto' => '自動承認',
                'paid_auto' => '支払い完了後に自動承認',
            ],
            'payment_modes' => [
                'free_or_paid_by_class' => '教室ごとに無料/有料を設定',
                'always_paid' => '常に有料',
                'free_first_then_paid' => '初回無料、その後有料',
                'ticket_or_subscription' => '回数券またはサブスク',
            ],
            'day_notice_modes' => [
                'day_of' => '当日に案内する',
                'after_approval' => '承認時に案内する',
                'one_day_before' => '前日に案内する',
            ],
            'attendance_gates' => [
                'approved_and_time_window' => '承認済み、かつ参加時間内',
                'approved_only' => '承認済みなら参加可能',
                'paid_or_free_and_time_window' => '支払い条件を満たし、かつ参加時間内',
            ],
        ];
    }

    private function settingFilled(string $key): bool {
        $value = trim((string)Settings::get($key, ''));
        return $value !== '';
    }

    private function currentBaseUrl(): string {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return '';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    public function testApi(): void {
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $type = $input['type'] ?? '';

        try {
            switch ($type) {
                case 'line':
                    $result = $this->testLine((string)($input['token'] ?? ''));
                    break;
                case 'claude':
                    $result = $this->testClaude((string)($input['key'] ?? ''));
                    break;
                case 'stability':
                    $result = $this->testStability((string)($input['key'] ?? ''));
                    break;
                case 'openai':
                    $result = $this->testOpenAI((string)($input['key'] ?? ''));
                    break;
                case 'grok':
                    $result = $this->testGrok((string)($input['key'] ?? ''));
                    break;
                case 'stripe':
                    require_once BASE_PATH . '/app/Services/StripeService.php';
                    $result = (new StripeService((string)($input['key'] ?? '')))->testConnection();
                    break;
                default:
                    $result = ['ok' => false, 'message' => 'Unknown API type'];
            }
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => 'Error: ' . $e->getMessage()];
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private function testLine(string $token): array {
        if ($token === '') {
            return ['ok' => false, 'message' => 'Access token is empty'];
        }
        $ch = curl_init('https://api.line.me/v2/bot/info');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $data = json_decode((string)$res, true);
            return ['ok' => true, 'message' => 'LINE connected: ' . ($data['displayName'] ?? '')];
        }
        return ['ok' => false, 'message' => "LINE failed: HTTP {$code}"];
    }

    private function testClaude(string $key): array {
        if ($key === '') {
            return ['ok' => false, 'message' => 'API key is empty'];
        }
        $body = json_encode([
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 16,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "x-api-key: {$key}",
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            return ['ok' => true, 'message' => 'Claude connected'];
        }
        $err = json_decode((string)$res, true);
        return ['ok' => false, 'message' => $err['error']['message'] ?? "Claude failed: HTTP {$code}"];
    }

    private function testStability(string $key): array {
        if ($key === '') {
            return ['ok' => false, 'message' => 'API key is empty'];
        }

        $endpoints = [
            'https://api.stability.ai/v1/user/balance',
            'https://api.stability.ai/v2beta/user/balance',
        ];
        $lastMessage = '';

        foreach ($endpoints as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$key}",
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 15,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                $lastMessage = "Stability communication error: {$err}";
                continue;
            }

            $data = json_decode((string)$res, true);
            if ($code === 200) {
                $credits = $data['credits'] ?? $data['balance'] ?? null;
                if (is_numeric($credits)) {
                    $credits = (float)$credits;
                    Settings::set('stability_credits_cache', (string)$credits);
                    Settings::set('stability_credits_checked_at', date('Y-m-d H:i:s'));
                    Settings::set('stability_credits_error', '');
                    $switch = $this->maybeSwitchImageEngineForStabilityBalance($credits);
                    $message = 'Stability connected. Credits: ' . number_format($credits, 2);
                    if ($switch['switched']) {
                        $message .= ' / Image engine switched to ' . $switch['engine'];
                    } elseif ($switch['message'] !== '') {
                        $message .= ' / ' . $switch['message'];
                    }
                    return [
                        'ok' => true,
                        'message' => $message,
                        'credits' => $credits,
                        'checked_at' => Settings::get('stability_credits_checked_at', ''),
                        'engine_switched' => $switch['switched'],
                        'image_engine' => Settings::get('image_engine', 'stability'),
                    ];
                }
                Settings::set('stability_credits_error', 'Balance response did not include credits.');
                return ['ok' => false, 'message' => 'Stability connected, but credits were not found in the response.'];
            }

            $apiMessage = $data['message'] ?? $data['error']['message'] ?? $data['error'] ?? trim((string)$res);
            if (is_array($apiMessage)) {
                $apiMessage = json_encode($apiMessage, JSON_UNESCAPED_UNICODE);
            }
            $lastMessage = "Stability failed: HTTP {$code}" . ($apiMessage !== '' ? " {$apiMessage}" : '');
        }

        $lastMessage = $lastMessage ?: 'Stability balance refresh failed.';
        Settings::set('stability_credits_error', $lastMessage);

        $switch = $this->maybeSwitchImageEngineForStabilityError($lastMessage);
        if ($switch['switched']) {
            return [
                'ok' => true,
                'message' => 'Stability残高を取得できないため、画像生成エンジンを ' . $switch['engine'] . ' に切り替えました。理由: ' . $lastMessage,
                'credits' => Settings::get('stability_credits_cache', ''),
                'checked_at' => Settings::get('stability_credits_checked_at', ''),
                'engine_switched' => true,
                'image_engine' => Settings::get('image_engine', 'stability'),
            ];
        }

        return ['ok' => false, 'message' => $lastMessage, 'image_engine' => Settings::get('image_engine', 'stability')];
    }

    private function maybeSwitchImageEngineForStabilityBalance(float $credits): array {
        $enabled = Settings::get('stability_auto_switch_enabled', '1') !== '0';
        if (!$enabled) {
            return ['switched' => false, 'engine' => Settings::get('image_engine', 'stability'), 'message' => 'Auto switch is disabled.'];
        }

        $threshold = (float)Settings::get('stability_auto_switch_threshold', '1');
        if ($credits > $threshold) {
            return ['switched' => false, 'engine' => Settings::get('image_engine', 'stability'), 'message' => ''];
        }

        $fallback = strtolower(trim(Settings::get('stability_fallback_engine', 'openai')));
        if (!$this->engineAvailable($fallback)) {
            $fallback = $this->engineAvailable('openai') ? 'openai' : ($this->engineAvailable('grok') ? 'grok' : '');
        }

        if ($fallback === '') {
            return ['switched' => false, 'engine' => Settings::get('image_engine', 'stability'), 'message' => 'No fallback engine API key is configured.'];
        }

        Settings::set('image_engine', $fallback);
        Settings::set('stability_last_auto_switch_at', date('Y-m-d H:i:s'));
        Settings::set('stability_last_auto_switch_reason', 'credits=' . $credits);
        return ['switched' => true, 'engine' => $fallback, 'message' => ''];
    }

    private function maybeSwitchImageEngineForStabilityError(string $message): array {
        if (Settings::get('stability_auto_switch_enabled', '1') === '0') {
            return ['switched' => false, 'engine' => Settings::get('image_engine', 'stability')];
        }

        $lower = strtolower($message);
        $shouldSwitch = false;
        foreach (['402', '403', 'credit', 'credits', 'balance', 'payment', 'billing', 'quota', 'insufficient'] as $needle) {
            if (strpos($lower, $needle) !== false) {
                $shouldSwitch = true;
                break;
            }
        }
        if (!$shouldSwitch) {
            return ['switched' => false, 'engine' => Settings::get('image_engine', 'stability')];
        }

        $fallback = strtolower(trim(Settings::get('stability_fallback_engine', 'openai')));
        if (!$this->engineAvailable($fallback)) {
            $fallback = $this->engineAvailable('openai') ? 'openai' : ($this->engineAvailable('grok') ? 'grok' : '');
        }
        if ($fallback === '') {
            return ['switched' => false, 'engine' => Settings::get('image_engine', 'stability')];
        }

        Settings::set('image_engine', $fallback);
        Settings::set('stability_last_auto_switch_at', date('Y-m-d H:i:s'));
        Settings::set('stability_last_auto_switch_reason', $message);
        return ['switched' => true, 'engine' => $fallback];
    }

    private function engineAvailable(string $engine): bool {
        if ($engine === 'openai') {
            return trim(Settings::get('openai_api_key', '')) !== '';
        }
        if ($engine === 'grok') {
            return trim(Settings::get('grok_api_key', '')) !== '';
        }
        if ($engine === 'stability') {
            return trim(Settings::stabilityApiKey()) !== '';
        }
        return false;
    }

    private function testOpenAI(string $key): array {
        if ($key === '') {
            return ['ok' => false, 'message' => 'APIキーが未入力です'];
        }
        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$key}"],
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            return ['ok' => false, 'message' => 'OpenAI通信エラー: ' . $err];
        }
        if ($code === 200) {
            return ['ok' => true, 'message' => 'OpenAI APIに接続できました'];
        }
        $data = json_decode((string)$res, true);
        $msg = $data['error']['message'] ?? "HTTP {$code}";
        return ['ok' => false, 'message' => 'OpenAI API接続失敗: ' . $msg];
    }

    private function testGrok(string $key): array {
        if ($key === '') {
            return ['ok' => false, 'message' => 'API key is empty'];
        }
        $ch = curl_init('https://api.x.ai/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$key}"],
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            return ['ok' => true, 'message' => 'Grok connected'];
        }
        $err = json_decode((string)$res, true);
        $msg = $err['error']['message'] ?? ($err['error'] ?? "HTTP {$code}");
        return ['ok' => false, 'message' => is_array($msg) ? json_encode($msg) : (string)$msg];
    }

    public function refreshStabilityCredits(): void {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $key = Settings::stabilityApiKey();
            if (!$key) {
                echo json_encode([
                    'ok' => false,
                    'message' => 'Stability AI APIキーが未設定です。API設定でキーを保存してください。',
                    'image_engine' => Settings::get('image_engine', 'stability'),
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode($this->testStability($key), JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            Settings::set('stability_credits_error', $e->getMessage());
            echo json_encode([
                'ok' => false,
                'message' => '残高更新中にエラーが発生しました: ' . $e->getMessage(),
                'image_engine' => Settings::get('image_engine', 'stability'),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function showUpdate(): void {
        $currentVersion = APP_VERSION;
        $error = null;
        $latestUpdateLog = $this->latestUpdateLog();
        $updateHistory = $this->updateHistory(30);
        $backupHistory = $this->backupHistory(20);
        require BASE_PATH . '/app/Views/admin/update.php';
    }

    public function runUpdate(): void {
        $this->uploadUpdate();
    }

    public function uploadUpdate(): void {
        $updateLog = [
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
            'status' => 'running',
            'uploaded_name' => $_FILES['update_zip']['name'] ?? '',
            'version_before' => APP_VERSION,
            'version_after' => null,
            'backup_dir' => null,
            'files' => [],
            'errors' => [],
        ];

        if (empty($_FILES['update_zip']) || $_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
            header('Location: /admin/update?error=' . urlencode('ZIP upload failed'));
            exit;
        }

        $zipPath = STORAGE_PATH . '/update_upload.zip';
        $tmpDir = STORAGE_PATH . '/update_tmp';
        $backupDir = STORAGE_PATH . '/update_backups/' . date('Ymd_His');
        $updateLog['backup_dir'] = $backupDir;

        try {
            if (!move_uploaded_file($_FILES['update_zip']['tmp_name'], $zipPath)) {
                throw new \RuntimeException('Failed to save uploaded ZIP');
            }
            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException('ZipArchive is not available');
            }

            $za = new \ZipArchive();
            if ($za->open($zipPath) !== true) {
                throw new \RuntimeException('Could not open ZIP file');
            }

            if (is_dir($tmpDir)) {
                $this->removeDir($tmpDir);
            }
            if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
                throw new \RuntimeException('Failed to create temp directory');
            }

            $realTmpDir = realpath($tmpDir);
            if ($realTmpDir === false) {
                throw new \RuntimeException('Temp directory is not available');
            }

            $allowedExt = ['php', 'html', 'css', 'js', 'json', 'sql', 'txt', 'md', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'lock', 'htaccess', ''];
            for ($i = 0; $i < $za->numFiles; $i++) {
                $rawName = (string)$za->getNameIndex($i);
                $name = $this->normalizeZipEntryName($rawName);
                if ($name === '' || substr($name, -1) === '/') {
                    continue;
                }
                if (strpos($name, '..') !== false || $name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new \RuntimeException("Invalid ZIP entry: {$rawName}");
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    throw new \RuntimeException("Invalid ZIP file extension: {$name}");
                }

                $destPath = $realTmpDir . '/' . $name;
                $destDir = dirname($destPath);
                if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
                    throw new \RuntimeException("Failed to create temp directory: {$destDir}");
                }

                $realDestDir = realpath($destDir);
                if ($realDestDir === false || strpos($realDestDir, $realTmpDir) !== 0) {
                    throw new \RuntimeException("Invalid ZIP destination: {$name}");
                }

                $data = $za->getFromIndex($i);
                if ($data === false) {
                    throw new \RuntimeException("Failed to read ZIP entry: {$name}");
                }
                if (file_put_contents($destPath, $data) === false) {
                    throw new \RuntimeException("Failed to extract ZIP entry: {$name}");
                }
                if (is_link($destPath)) {
                    unlink($destPath);
                    throw new \RuntimeException("ZIP symbolic links are not allowed: {$name}");
                }
            }
            $za->close();

            $srcBase = $tmpDir;
            $items = array_values(array_diff(scandir($tmpDir), ['.', '..']));
            if (count($items) === 1 && is_dir($tmpDir . '/' . $items[0])) {
                $srcBase = $tmpDir . '/' . $items[0];
            }

            $protected = [
                BASE_PATH . '/config/db.php',
                BASE_PATH . '/config/installed.lock',
                BASE_PATH . '/storage',
                BASE_PATH . '/uploads',
            ];

            $this->copyDir($srcBase, BASE_PATH, $protected, $backupDir, $updateLog);

            $srcVersion = $srcBase . '/VERSION';
            if (is_file($srcVersion)) {
                $this->copyFile($srcVersion, BASE_PATH . '/VERSION', $backupDir, $updateLog);
            }
            if (is_file(BASE_PATH . '/VERSION')) {
                $updateLog['version_after'] = trim((string)file_get_contents(BASE_PATH . '/VERSION'));
            }

            @unlink($zipPath);
            $this->removeDir($tmpDir);
            $updateLog['finished_at'] = date('Y-m-d H:i:s');
            $updateLog['status'] = 'success';
            $this->writeUpdateLog($updateLog);

            header('Location: /admin/update?updated=1');
            exit;
        } catch (\Throwable $e) {
            @unlink($zipPath);
            if (is_dir($tmpDir)) {
                $this->removeDir($tmpDir);
            }
            $updateLog['finished_at'] = date('Y-m-d H:i:s');
            $updateLog['status'] = 'failed';
            $updateLog['errors'][] = $e->getMessage();
            $this->writeUpdateLog($updateLog);
            header('Location: /admin/update?error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    public function rollbackUpdate(): void {
        $backupName = basename((string)($_POST['backup'] ?? $_GET['backup'] ?? ''));
        if ($backupName === '') {
            header('Location: /admin/update?error=' . urlencode('Backup is not selected'));
            exit;
        }

        $backupDir = STORAGE_PATH . '/update_backups/' . $backupName;
        $rollbackLog = [
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
            'status' => 'running',
            'type' => 'rollback',
            'backup_name' => $backupName,
            'backup_dir' => $backupDir,
            'version_before' => APP_VERSION,
            'version_after' => null,
            'files' => [],
            'errors' => [],
        ];

        try {
            if (!is_dir($backupDir)) {
                throw new \RuntimeException('Backup directory not found: ' . $backupName);
            }
            $this->restoreBackupDir($backupDir, $rollbackLog);
            if (is_file(BASE_PATH . '/VERSION')) {
                $rollbackLog['version_after'] = trim((string)file_get_contents(BASE_PATH . '/VERSION'));
            }
            $rollbackLog['finished_at'] = date('Y-m-d H:i:s');
            $rollbackLog['status'] = 'success';
            $this->writeUpdateLog($rollbackLog);
            header('Location: /admin/update?rollback=1');
            exit;
        } catch (\Throwable $e) {
            $rollbackLog['finished_at'] = date('Y-m-d H:i:s');
            $rollbackLog['status'] = 'failed';
            $rollbackLog['errors'][] = $e->getMessage();
            $this->writeUpdateLog($rollbackLog);
            header('Location: /admin/update?error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    private function normalizeZipEntryName(string $name): string {
        $name = str_replace('\\', '/', $name);
        $name = preg_replace('#/+#', '/', $name) ?? $name;
        $name = preg_replace('#^\./+#', '', $name) ?? $name;
        return trim($name);
    }

    private function copyDir(string $src, string $dst, array $protectedDsts = [], string $backupDir = '', array &$updateLog = []): void {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $rel = substr($item->getPathname(), strlen($src));
            $target = rtrim($dst, '/\\') . $rel;
            $skip = false;
            foreach ($protectedDsts as $protected) {
                if ($target === $protected || strpos($target, rtrim($protected, '/\\') . DIRECTORY_SEPARATOR) === 0 || strpos($target, rtrim($protected, '/\\') . '/') === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$target}");
                }
            } else {
                $this->copyFile($item->getPathname(), $target, $backupDir, $updateLog);
            }
        }
    }

    private function copyFile(string $src, string $target, string $backupDir = '', array &$updateLog = []): void {
        if (is_dir($src)) {
            return;
        }
        if (is_dir($target)) {
            $updateLog['files'][] = [
                'path' => $this->relativePath($target),
                'before' => null,
                'after' => null,
                'bytes' => 0,
                'skipped' => 'target_is_directory',
            ];
            return;
        }
        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new \RuntimeException("Failed to create target directory: {$targetDir}");
        }

        $beforeHash = is_file($target) ? hash_file('sha256', $target) : null;
        if ($beforeHash !== null && $backupDir !== '') {
            $rel = $this->relativePath($target);
            $backupPath = rtrim($backupDir, '/\\') . '/' . $rel;
            $backupPathDir = dirname($backupPath);
            if (!is_dir($backupPathDir) && !mkdir($backupPathDir, 0755, true)) {
                throw new \RuntimeException("Failed to create backup directory: {$backupPathDir}");
            }
            if (is_dir($backupPath)) {
                $backupPath = rtrim($backupPath, '/\\') . '/__directory_conflict__';
            }
            if (!copy($target, $backupPath)) {
                $error = error_get_last();
                throw new \RuntimeException("Backup failed: {$backupPath} / " . ($error['message'] ?? 'unknown'));
            }
        }

        if (is_file($target) && !is_writable($target)) {
            @chmod($target, 0644);
        }
        if (!is_writable($targetDir)) {
            @chmod($targetDir, 0755);
        }
        if (!copy($src, $target)) {
            $error = error_get_last();
            throw new \RuntimeException("File update failed: {$target} / " . ($error['message'] ?? 'unknown'));
        }
        @chmod($target, 0644);
        $this->invalidatePhpCache($target);

        $updateLog['files'][] = [
            'path' => $this->relativePath($target),
            'before' => $beforeHash,
            'after' => is_file($target) ? hash_file('sha256', $target) : null,
            'bytes' => is_file($target) ? filesize($target) : 0,
            'backed_up' => $beforeHash !== null && $backupDir !== '',
        ];
    }

    private function invalidatePhpCache(string $path): void {
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate') && is_file($path)) {
            @opcache_invalidate($path, true);
        }
    }

    private function relativePath(string $path): string {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', BASE_PATH);
        if (strpos($path, $base . '/') === 0) {
            return substr($path, strlen($base) + 1);
        }
        return basename($path);
    }

    private function updateLogDir(): string {
        return STORAGE_PATH . '/update_logs';
    }

    private function writeUpdateLog(array $log): void {
        $dir = $this->updateLogDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = ($log['type'] ?? 'update') . '_' . date('Ymd_His') . '.json';
        @file_put_contents($dir . '/' . $name, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function latestUpdateLog(): ?array {
        $items = $this->updateHistory(1);
        return $items[0] ?? null;
    }

    private function updateHistory(int $limit = 30): array {
        $dir = $this->updateLogDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.json') ?: [];
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $items = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $data['_file'] = basename($file);
                $items[] = $data;
            }
        }
        return $items;
    }

    private function backupHistory(int $limit = 20): array {
        $base = STORAGE_PATH . '/update_backups';
        if (!is_dir($base)) {
            return [];
        }
        $dirs = array_filter(glob($base . '/*') ?: [], 'is_dir');
        usort($dirs, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $items = [];
        foreach (array_slice($dirs, 0, $limit) as $dir) {
            $items[] = [
                'name' => basename($dir),
                'path' => $dir,
                'mtime' => filemtime($dir),
            ];
        }
        return $items;
    }

    private function restoreBackupDir(string $backupDir, array &$rollbackLog): void {
        $realBackup = realpath($backupDir);
        $realBase = realpath(BASE_PATH);
        if ($realBackup === false || $realBase === false) {
            throw new \RuntimeException('Backup or base path is not available');
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $source = $item->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($source, strlen($backupDir))), '/');
            if ($rel === '' || strpos($rel, '..') !== false) {
                continue;
            }
            $target = BASE_PATH . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true)) {
                    throw new \RuntimeException('Failed to create restore directory: ' . $rel);
                }
                continue;
            }
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
                throw new \RuntimeException('Failed to create restore target directory: ' . $rel);
            }
            if (!copy($source, $target)) {
                $error = error_get_last();
                throw new \RuntimeException('Failed to restore file: ' . $rel . ' / ' . ($error['message'] ?? 'unknown'));
            }
            @chmod($target, 0644);
            $this->invalidatePhpCache($target);
            $rollbackLog['files'][] = [
                'path' => $rel,
                'after' => is_file($target) ? hash_file('sha256', $target) : null,
                'bytes' => is_file($target) ? filesize($target) : 0,
            ];
        }
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}

