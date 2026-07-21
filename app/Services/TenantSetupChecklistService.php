<?php
require_once BASE_PATH . '/app/Services/TenantService.php';

class TenantSetupChecklistService {
    private TenantService $tenants;

    public function __construct(?TenantService $tenants = null) {
        $this->tenants = $tenants ?: new TenantService();
    }

    public function summaries(array $tenantRows): array {
        $summaries = [];
        foreach ($tenantRows as $tenant) {
            $tenantId = (int)($tenant['id'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }
            $summaries[$tenantId] = $this->summaryFor($tenant);
        }
        return $summaries;
    }

    private function summaryFor(array $tenant): array {
        $settings = $this->tenants->settings((int)$tenant['id']);
        $groups = [
            'basic' => $this->checkBasic($tenant, $settings),
            'public' => $this->checkPublic($settings),
            'line' => $this->checkLine($settings),
            'stripe' => $this->checkStripe($settings),
            'ai' => $this->checkAi($settings),
            'operation' => $this->checkOperation($settings),
        ];

        $total = count($groups);
        $completed = 0;
        $warnings = [];
        foreach ($groups as $group) {
            if (($group['status'] ?? '') === 'ok') {
                $completed++;
            } else {
                $warnings[] = $group['label'] ?? '未設定';
            }
        }

        $score = $total > 0 ? (int)floor(($completed / $total) * 100) : 0;
        $level = 'danger';
        if ($score >= 90) {
            $level = 'ok';
        } elseif ($score >= 60) {
            $level = 'warning';
        }

        return [
            'score' => $score,
            'level' => $level,
            'completed' => $completed,
            'total' => $total,
            'groups' => $groups,
            'warnings' => $warnings,
        ];
    }

    private function checkBasic(array $tenant, array $settings): array {
        $missing = [];
        if ($this->blank($tenant['name'] ?? '')) {
            $missing[] = 'クライアント名';
        }
        if ($this->blank($tenant['tenant_key'] ?? '')) {
            $missing[] = 'クライアントキー';
        }
        if ($this->blank($tenant['primary_domain'] ?? '') && $this->blank($settings['public_base_url'] ?? '')) {
            $missing[] = 'ドメインまたは公開URL';
        }
        if ($this->blank($tenant['service_name'] ?? '') && $this->blank($settings['service_name'] ?? '')) {
            $missing[] = 'サービス名';
        }
        return $this->result('基本情報', $missing);
    }

    private function checkPublic(array $settings): array {
        $missing = [];
        foreach ([
            'client_company_name' => '会社名・団体名',
            'client_contact_email' => '問い合わせメール',
            'client_address' => '所在地',
        ] as $key => $label) {
            if ($this->blank($settings[$key] ?? '')) {
                $missing[] = $label;
            }
        }
        return $this->result('公開ページ・法務表記', $missing);
    }

    private function checkLine(array $settings): array {
        $missing = [];
        $required = [
            'line_official_id' => 'LINE公式ID',
            'line_channel_secret' => 'Channel Secret',
            'line_channel_access_token' => 'Channel Access Token',
        ];
        $operationType = (string)($settings['service_operation_type'] ?? 'class_school');
        if (in_array($operationType, ['class_school', 'hybrid', 'event_campaign'], true)) {
            $required['liff_id'] = '予約用LIFF ID';
        }
        if (in_array($operationType, ['online_generation', 'hybrid', 'event_campaign'], true)) {
            $required['generate_liff_id'] = '画像生成用LIFF ID';
        }
        foreach ($required as $key => $label) {
            if ($this->blank($settings[$key] ?? '')) {
                $missing[] = $label;
            }
        }
        return $this->result('LINE・LIFF', $missing);
    }

    private function checkStripe(array $settings): array {
        $missing = [];
        foreach ([
            'stripe_secret_key' => 'シークレットキー',
            'stripe_publishable_key' => '公開可能キー',
            'stripe_webhook_secret' => 'Webhook署名シークレット',
        ] as $key => $label) {
            if ($this->blank($settings[$key] ?? '')) {
                $missing[] = $label;
            }
        }

        $hasProduct = !$this->blank($settings['stripe_subscription_price_id'] ?? '')
            || !$this->blank($settings['stripe_annual_subscription_price_id'] ?? '')
            || !$this->blank($settings['one_time_price_id'] ?? '')
            || !$this->blank($settings['ticket_plans'] ?? '');
        if (!$hasProduct) {
            $missing[] = '料金プラン Price ID または回数券プラン';
        }

        return $this->result('Stripe・料金', $missing);
    }

    private function checkAi(array $settings): array {
        $missing = [];
        $hasApi = !$this->blank($settings['openai_api_key'] ?? '')
            || !$this->blank($settings['stability_api_key'] ?? '')
            || !$this->blank($settings['grok_api_key'] ?? '');
        if (!$hasApi) {
            $missing[] = 'AI APIキー';
        }
        if ($this->blank($settings['image_engine'] ?? '') && $this->blank($settings['image_human_safe_engine'] ?? '')) {
            $missing[] = '画像生成エンジン';
        }
        return $this->result('AI生成', $missing);
    }

    private function checkOperation(array $settings): array {
        $missing = [];
        foreach ([
            'line_monthly_limit' => 'LINE月間送信上限',
            'daily_request_limit' => '1日の依頼数',
            'max_images_per_request' => '1依頼あたり生成枚数',
            'workflow_approval_mode' => '承認方式',
            'workflow_attendance_gate' => '参加・生成条件',
        ] as $key => $label) {
            if ($this->blank($settings[$key] ?? '')) {
                $missing[] = $label;
            }
        }
        return $this->result('運用ルール', $missing);
    }

    private function result(string $label, array $missing): array {
        return [
            'label' => $label,
            'status' => empty($missing) ? 'ok' : 'missing',
            'missing' => $missing,
        ];
    }

    private function blank($value): bool {
        return trim((string)$value) === '';
    }
}
