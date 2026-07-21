<?php

require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/TenantService.php';

class TenantConnectionCheckService
{
    private TenantService $tenants;

    public function __construct(?TenantService $tenants = null)
    {
        $this->tenants = $tenants ?: new TenantService();
    }

    public function diagnostics(array $tenant, array $tenantUrls): array
    {
        $settings = $this->effectiveSettings($tenant);
        $groups = [
            'line' => $this->lineChecks($settings, $tenantUrls),
            'liff' => $this->liffChecks($settings, $tenantUrls),
            'stripe' => $this->stripeChecks($settings, $tenantUrls),
            'ai' => $this->aiChecks($settings),
            'public' => $this->publicChecks($tenant, $settings),
            'operation' => $this->operationChecks($settings),
        ];

        $total = 0;
        $ok = 0;
        $warnings = 0;
        foreach ($groups as $group) {
            foreach (($group['items'] ?? []) as $item) {
                $total++;
                if (($item['status'] ?? '') === 'ok') {
                    $ok++;
                } elseif (($item['status'] ?? '') === 'warning') {
                    $warnings++;
                }
            }
        }

        return [
            'score' => $total > 0 ? (int)floor(($ok / $total) * 100) : 0,
            'total' => $total,
            'ok' => $ok,
            'warnings' => $warnings,
            'ng' => max(0, $total - $ok - $warnings),
            'groups' => $groups,
            'settings' => $settings,
        ];
    }

    private function effectiveSettings(array $tenant): array
    {
        $tenantId = (int)($tenant['id'] ?? 0);
        $settings = $this->tenants->settings($tenantId);
        if (empty($tenant['is_default'])) {
            return $settings;
        }

        foreach (array_keys($this->tenants->settingSchema()) as $key) {
            if (trim((string)($settings[$key] ?? '')) === '') {
                $settings[$key] = Settings::get($key, '');
            }
        }
        return $settings;
    }

    private function lineChecks(array $settings, array $urls): array
    {
        return [
            'label' => 'LINE Messaging API',
            'items' => [
                $this->required('LINE公式アカウントID', $settings['line_official_id'] ?? '', 'LINE Official Account Managerのアカウント設定で確認します。'),
                $this->required('Channel Secret', $settings['line_channel_secret'] ?? '', 'LINE Official Account ManagerのMessaging API画面で確認します。'),
                $this->required('Channel Access Token', $settings['line_channel_access_token'] ?? '', 'LINE DevelopersのMessaging APIチャネルで長期トークンを発行します。'),
                $this->url('Webhook URL', $urls['line_webhook'] ?? '', 'このURLをLINE Official Account ManagerのWebhook URLへ登録します。'),
            ],
            'manual_test' => [
                'Webhook URLを保存し、Webhookの利用を有効にします。',
                'LINE Developersまたは公式アカウント管理画面の検証で成功することを確認します。',
                '友だち追加後にテストメッセージを送り、操作ログへ記録されることを確認します。',
            ],
        ];
    }

    private function liffChecks(array $settings, array $urls): array
    {
        $operationType = (string)($settings['service_operation_type'] ?? 'class_school');
        $usesClasses = in_array($operationType, ['class_school', 'hybrid', 'event_campaign'], true);
        $usesGeneration = in_array($operationType, ['online_generation', 'hybrid', 'event_campaign'], true);
        $usesShop = $this->usesPayment($settings);
        $items = [];

        $items[] = $usesClasses
            ? $this->required('予約用LIFF ID', $settings['liff_id'] ?? '', '予約カレンダー専用LIFFのIDを登録します。')
            : $this->optional('予約用LIFF ID', $settings['liff_id'] ?? '', '予約を使わない運用では未設定で構いません。');
        if ($usesClasses) {
            $items[] = $this->url('予約カレンダーURL', $urls['calendar_liff'] ?? '', '予約用LIFFのEndpoint URLへ登録します。');
        }

        $items[] = $usesGeneration
            ? $this->required('画像生成用LIFF ID', $settings['generate_liff_id'] ?? '', '画像生成ページ専用LIFFのIDを登録します。')
            : $this->optional('画像生成用LIFF ID', $settings['generate_liff_id'] ?? '', 'LINE上で画像生成しない場合は未設定で構いません。');
        if ($usesGeneration) {
            $items[] = $this->url('画像生成URL', $urls['generate_liff'] ?? '', '画像生成用LIFFのEndpoint URLへ登録します。');
        }

        $items[] = $usesShop
            ? $this->required('購入用LIFF ID', $settings['shop_liff_id'] ?? '', '決済を使う場合は購入ページ専用LIFFを作成します。')
            : $this->optional('購入用LIFF ID', $settings['shop_liff_id'] ?? '', '決済を使わない場合は未設定で構いません。');
        if ($usesShop) {
            $items[] = $this->url('購入ページURL', $urls['shop_liff'] ?? '', '購入用LIFFのEndpoint URLへ登録します。');
        }

        return [
            'label' => 'LIFF',
            'items' => $items,
            'manual_test' => [
                '各LIFFのEndpoint URLには、画面に表示されたテナント専用URLをそのまま登録します。',
                '予約を使わない画像生成専用運用では、画像生成用LIFFだけを必須にします。',
                'LINEアプリ内のリッチメニューから開き、別テナントの画面へ移動しないことを確認します。',
            ],
        ];
    }

    private function stripeChecks(array $settings, array $urls): array
    {
        if (!$this->usesPayment($settings)) {
            return [
                'label' => 'Stripe決済',
                'items' => [$this->informational('決済機能', '未使用', '決済を使うときにStripeキー、Webhook、Price IDを設定します。')],
                'manual_test' => ['決済を開始するまでは追加設定は不要です。'],
            ];
        }

        return [
            'label' => 'Stripe決済',
            'items' => [
                $this->prefix('シークレットキー', $settings['stripe_secret_key'] ?? '', ['sk_test_', 'sk_live_'], 'StripeのAPIキーを登録します。'),
                $this->prefix('公開可能キー', $settings['stripe_publishable_key'] ?? '', ['pk_test_', 'pk_live_'], 'シークレットキーと同じモードのキーを登録します。'),
                $this->prefix('Webhook署名シークレット', $settings['stripe_webhook_secret'] ?? '', ['whsec_'], 'Webhookエンドポイント作成後に表示される値です。'),
                $this->url('Stripe Webhook URL', $urls['stripe_webhook'] ?? '', 'Stripe DashboardのWebhookへ登録します。'),
                $this->priceId('月額サブスク Price ID', $settings['stripe_subscription_price_id'] ?? ''),
                $this->priceId('年額サブスク Price ID', $settings['stripe_annual_subscription_price_id'] ?? ''),
                $this->priceId('一回払い Price ID', $settings['one_time_price_id'] ?? ''),
                $this->ticketPlan('回数券プラン', $settings['ticket_plans'] ?? ''),
            ],
            'manual_test' => [
                'テスト環境ではsk_test_、pk_test_、テスト環境で作成したprice_をそろえます。',
                '本番環境ではsk_live_、pk_live_、本番環境で作成したprice_をそろえます。',
                'Webhookでcheckout.session.completedなど必要なイベントが成功することを確認します。',
            ],
        ];
    }

    private function aiChecks(array $settings): array
    {
        $engine = strtolower(trim((string)($settings['image_engine'] ?? '')));
        $keyMap = [
            'openai' => trim((string)($settings['openai_api_key'] ?? '')),
            'stability' => trim((string)($settings['stability_api_key'] ?? '')),
            'grok' => trim((string)($settings['grok_api_key'] ?? '')),
        ];
        $selected = str_contains($engine, 'openai') ? 'openai' : (str_contains($engine, 'grok') || str_contains($engine, 'xai') ? 'grok' : 'stability');
        $selectedKey = $keyMap[$selected] ?? '';

        return [
            'label' => 'AI画像生成',
            'items' => [
                $this->any('AI APIキー', array_values($keyMap), 'OpenAI、Stability AI、Grokのいずれかを設定します。'),
                $this->required('通常の画像生成エンジン', $settings['image_engine'] ?? '', '実際に使用する画像生成エンジンです。'),
                $selectedKey !== ''
                    ? $this->informational('選択エンジンのAPIキー', '設定済み', '選択中のエンジンに対応するAPIキーがあります。')
                    : $this->missing('選択エンジンのAPIキー', '未設定', '選択中のエンジンに対応するAPIキーを設定してください。'),
                $this->required('1依頼あたりの生成枚数', $settings['max_images_per_request'] ?? '', 'コストとLINE送信数に影響します。'),
                $this->required('1日最大依頼数', $settings['daily_request_limit'] ?? '', 'ユーザーごとの生成上限です。'),
            ],
            'manual_test' => [
                'テストユーザーで1件依頼し、生成完了後にLINEへ画像が届くことを確認します。',
                '失敗時にAPIエラーが依頼詳細とテナント監査画面へ記録されることを確認します。',
            ],
        ];
    }

    private function publicChecks(array $tenant, array $settings): array
    {
        return [
            'label' => '公開ページ・法定表記',
            'items' => [
                $this->required('公開ドメイン', $tenant['primary_domain'] ?? ($settings['public_base_url'] ?? ''), 'LP、規約、プライバシーポリシーに使います。'),
                $this->required('会社名または団体名', $settings['client_company_name'] ?? '', '利用規約と特商法ページへ反映します。'),
                $this->required('問い合わせメール', $settings['client_contact_email'] ?? '', '公開ページの連絡先です。'),
                $this->required('所在地', $settings['client_address'] ?? '', '特商法ページで必要です。'),
            ],
            'manual_test' => [
                'LP、利用規約、プライバシーポリシー、特商法ページを開いて表示内容を確認します。',
                'クライアント名、連絡先、料金、キャンセル条件が対象テナントの内容になっていることを確認します。',
            ],
        ];
    }

    private function operationChecks(array $settings): array
    {
        $operationType = (string)($settings['service_operation_type'] ?? 'class_school');
        $items = [
            $this->required('運用タイプ', $operationType, '教室運営、オンライン生成、併用などの運用区分です。'),
            $this->required('LINE月間送信上限', $settings['line_monthly_limit'] ?? '', 'LINE公式アカウントの契約プランに合わせます。'),
            $this->required('画像生成上限', $settings['max_images_per_request'] ?? '', '1依頼で生成する画像枚数です。'),
        ];
        if (in_array($operationType, ['class_school', 'hybrid', 'event_campaign'], true)) {
            $items[] = $this->required('承認方式', $settings['workflow_approval_mode'] ?? '', '自動承認または手動承認を設定します。');
            $items[] = $this->required('参加・生成条件', $settings['workflow_attendance_gate'] ?? '', '開催時間外に生成できないようにする条件です。');
        }
        if (in_array($operationType, ['online_generation', 'hybrid'], true)) {
            $items[] = $this->required('生成利用方式', $settings['generation_access_mode'] ?? '', '予約不要または参加連動などの生成条件です。');
        }

        return [
            'label' => '運用ルール',
            'items' => $items,
            'manual_test' => [
                '設定した曜日・時間・上限の内外で生成可否が正しく変わることを確認します。',
                '予約を使わない運用では、予約メニューと予約用LIFFを表示しないことを確認します。',
            ],
        ];
    }

    private function usesPayment(array $settings): bool
    {
        foreach (['stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret', 'stripe_subscription_price_id', 'stripe_annual_subscription_price_id', 'one_time_price_id', 'ticket_plans'] as $key) {
            if (trim((string)($settings[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function required(string $label, $value, string $hint): array
    {
        $filled = trim((string)$value) !== '';
        return ['label' => $label, 'status' => $filled ? 'ok' : 'ng', 'value' => $filled ? '設定済み' : '未設定', 'hint' => $hint];
    }

    private function optional(string $label, $value, string $hint): array
    {
        $filled = trim((string)$value) !== '';
        return ['label' => $label, 'status' => $filled ? 'ok' : 'warning', 'value' => $filled ? '設定済み' : '任意', 'hint' => $hint];
    }

    private function informational(string $label, string $value, string $hint): array
    {
        return ['label' => $label, 'status' => 'ok', 'value' => $value, 'hint' => $hint];
    }

    private function missing(string $label, string $value, string $hint): array
    {
        return ['label' => $label, 'status' => 'ng', 'value' => $value, 'hint' => $hint];
    }

    private function any(string $label, array $values, string $hint): array
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return $this->informational($label, '設定済み', $hint);
            }
        }
        return $this->missing($label, '未設定', $hint);
    }

    private function url(string $label, string $value, string $hint): array
    {
        $valid = filter_var($value, FILTER_VALIDATE_URL) !== false;
        return ['label' => $label, 'status' => $valid ? 'ok' : 'ng', 'value' => $valid ? $value : '未設定または不正', 'hint' => $hint];
    }

    private function prefix(string $label, $value, array $prefixes, string $hint): array
    {
        $text = trim((string)$value);
        $valid = false;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                $valid = true;
                break;
            }
        }
        return ['label' => $label, 'status' => $valid ? 'ok' : 'ng', 'value' => $valid ? '設定済み' : '未設定または形式不正', 'hint' => $hint];
    }

    private function priceId(string $label, $value): array
    {
        $text = trim((string)$value);
        if ($text === '') {
            return $this->optional($label, '', '使わないプランは空欄で構いません。使う場合はprice_で始まるPrice IDを登録します。');
        }
        return str_starts_with($text, 'price_')
            ? $this->informational($label, '設定済み', 'Stripeの商品価格で作成したPrice IDです。')
            : $this->missing($label, '形式不正', 'prod_ではなくprice_で始まるPrice IDを登録してください。');
    }

    private function ticketPlan(string $label, $value): array
    {
        $text = trim((string)$value);
        if ($text === '') {
            return $this->optional($label, '', '回数券を販売しない場合は未設定で構いません。');
        }
        $decoded = json_decode($text, true);
        return is_array($decoded)
            ? $this->informational($label, '設定済み', '回数券プランが登録されています。')
            : $this->missing($label, 'JSON形式不正', '管理画面で回数と料金を設定し直してください。');
    }
}
