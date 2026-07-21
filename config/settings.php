<?php
require_once __DIR__ . '/database.php';

class Settings {
    private static array $cache = [];
    private static bool $loaded = false;
    private static ?array $tenant = null;
    private static ?int $runtimeTenantId = null;

    public static function load(): void {
        if (self::$loaded) {
            return;
        }

        try {
            $pdo = get_pdo();
            self::ensureTenantTables($pdo);

            $rows = $pdo->query("SELECT `key`, `value` FROM system_settings")->fetchAll();
            foreach ($rows as $row) {
                self::$cache[(string)$row['key']] = (string)($row['value'] ?? '');
            }

            self::$tenant = self::detectTenant($pdo);
            if (self::$tenant) {
                if (empty(self::$tenant['is_default'])) {
                    self::applyTenantIsolationDefaults();
                }
                $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?');
                $stmt->execute([(int)self::$tenant['id']]);
                foreach ($stmt->fetchAll() as $row) {
                    $key = (string)$row['setting_key'];
                    $value = (string)($row['setting_value'] ?? '');
                    if ($key !== '') {
                        self::$cache[$key] = $value;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Keep the application usable even before installation or during DB repair.
        }

        self::$loaded = true;
    }

    public static function reload(): void {
        self::$cache = [];
        self::$loaded = false;
        self::$tenant = null;
        self::load();
    }

    public static function get(string $key, string $default = ''): string {
        self::load();
        return array_key_exists($key, self::$cache) ? (string)self::$cache[$key] : $default;
    }

    public static function set(string $key, string $value): void {
        $pdo = get_pdo();
        self::ensureTenantTables($pdo);
        self::load();

        $tenant = self::$tenant;
        if ($tenant && empty($tenant['is_default'])) {
            $stmt = $pdo->prepare("
                INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            $stmt->execute([(int)$tenant['id'], $key, $value]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (`key`, `value`, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE `value` = ?, updated_at = NOW()
            ");
            $stmt->execute([$key, $value, $value]);
        }

        self::$cache[$key] = $value;
    }

    public static function all(): array {
        self::load();
        return self::$cache;
    }

    public static function currentTenant(): ?array {
        self::load();
        return self::$tenant;
    }

    public static function tenantId(): ?int {
        self::load();
        return self::$tenant ? (int)self::$tenant['id'] : null;
    }

    public static function useTenantId(?int $tenantId): void {
        self::$runtimeTenantId = $tenantId && $tenantId > 0 ? (int)$tenantId : null;
        self::reload();
    }

    public static function lineChannelSecret(): string { return self::get('line_channel_secret'); }
    public static function lineAccessToken(): string { return self::get('line_channel_access_token'); }
    public static function claudeApiKey(): string { return self::get('claude_api_key'); }
    public static function stabilityApiKey(): string { return self::get('stability_api_key'); }
    public static function storageDriver(): string { return self::get('storage_driver', 'local'); }
    public static function storagePublicUrl(): string { return self::get('storage_public_url'); }
    public static function r2AccountId(): string { return self::get('r2_account_id'); }
    public static function r2AccessKey(): string { return self::get('r2_access_key'); }
    public static function r2SecretKey(): string { return self::get('r2_secret_key'); }
    public static function r2Bucket(): string { return self::get('r2_bucket'); }
    public static function maxDailyPerUser(): int { return (int)self::get('max_daily_requests_per_user', self::get('daily_request_limit', '2')); }
    public static function maxImagesPerRequest(): int { return (int)self::get('max_images_per_request', '8'); }
    public static function adminEmail(): string { return self::get('admin_email'); }

    private static function ensureTenantTables(PDO $pdo): void {
        $pdo->exec("
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

        $pdo->exec("
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

    private static function applyTenantIsolationDefaults(): void {
        foreach (array_keys(self::$cache) as $key) {
            if (self::isTenantIsolatedKey((string)$key)) {
                unset(self::$cache[$key]);
            }
        }

        foreach (self::tenantIsolatedDefaults() as $key => $value) {
            self::$cache[$key] = $value;
        }
    }

    private static function isTenantIsolatedKey(string $key): bool {
        foreach (self::tenantIsolatedPrefixes() as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function tenantIsolatedPrefixes(): array {
        return [
            'admin_',
            'annual_',
            'claude_',
            'class_mode_',
            'client_',
            'contact_',
            'greeting_',
            'grok_',
            'image_',
            'images_per_',
            'integration_',
            'liff_',
            'line_',
            'mail_',
            'max_daily_',
            'max_images_',
            'next_class_',
            'ng_',
            'one_time_',
            'openai_',
            'payment_',
            'photo_',
            'privacy_',
            'prompt_',
            'public_',
            'r2_',
            'resend_',
            'rich_menu_',
            'service_',
            'shopping_',
            'stability_',
            'storage_',
            'stripe_',
            'subscription_',
            'survey_',
            'terms_',
            'ticket_',
            'worker_last_',
            'workflow_',
            'generation_',
            'daily_request_',
            'first_visit_',
        ];
    }

    private static function tenantIsolatedDefaults(): array {
        return [
            'service_operation_type' => 'class_school',
            'service_name' => '',
            'classroom_name' => '',
            'public_base_url' => '',
            'client_name' => '',
            'client_service_name' => '',
            'client_company_name' => '',
            'client_operator_name' => '',
            'client_contact_email' => '',
            'client_contact_phone' => '',
            'client_address' => '',
            'integration_enabled' => '0',
            'integration_common_id_base_url' => '',
            'integration_project_key' => 'ai-art-school',
            'integration_key_id' => '',
            'integration_hmac_secret' => '',
            'integration_timeout_seconds' => '10',
            'terms_url' => '',
            'privacy_url' => '',
            'line_official_id' => '',
            'line_channel_secret' => '',
            'line_channel_access_token' => '',
            'line_login_channel_id' => '',
            'line_image_max_side' => '2048',
            'line_image_jpeg_quality' => '90',
            'admin_line_user_id' => '',
            'greeting_message' => '',
            'contact_message' => '',
            'next_class_message' => '',
            'liff_id' => '',
            'liff_channel_id' => '',
            'shop_liff_id' => '',
            'generate_liff_id' => '',
            'rich_menu_segments_enabled' => '0',
            'rich_menu_delivery_mode' => 'segments',
            'rich_menu_online_default_id' => '',
            'rich_menu_segment_first_time_id' => '',
            'rich_menu_segment_attended_id' => '',
            'rich_menu_segment_ticket_id' => '',
            'rich_menu_segment_subscriber_id' => '',
            'rich_menu_segments_last_sync_at' => '',
            'rich_menu_segments_last_sync_result' => '',
            'payment_provider' => 'local_stripe',
            'shopping_checkout_base_url' => '',
            'shopping_key_id' => '',
            'shopping_hmac_secret' => '',
            'shopping_product_map_json' => '',
            'shopping_webhook_tolerance_seconds' => '300',
            'stripe_secret_key' => '',
            'stripe_publishable_key' => '',
            'stripe_webhook_secret' => '',
            'stripe_subscription_price_id' => '',
            'stripe_annual_subscription_price_id' => '',
            'one_time_price_id' => '',
            'ticket_plans' => '',
            'ticket_valid_days' => '180',
            'subscription_price_label' => '',
            'annual_subscription_price_label' => '',
            'admin_email' => '',
            'admin_notify_email' => '0',
            'admin_notify_events' => '',
            'resend_api_key' => '',
            'mail_from' => '',
            'storage_driver' => 'local',
            'storage_public_url' => '',
            'r2_account_id' => '',
            'r2_access_key' => '',
            'r2_secret_key' => '',
            'r2_bucket' => '',
            'openai_api_key' => '',
            'openai_image_model' => 'gpt-image-1',
            'openai_image_size' => '1024x1024',
            'openai_prompt_model' => 'gpt-4.1-mini',
            'claude_api_key' => '',
            'stability_api_key' => '',
            'stability_model' => 'sdxl',
            'stability_auto_switch_enabled' => '1',
            'stability_auto_switch_threshold' => '1',
            'stability_fallback_engine' => 'openai',
            'stability_credits_cache' => '',
            'stability_credits_checked_at' => '',
            'stability_credits_error' => '',
            'grok_api_key' => '',
            'grok_image_model' => 'grok-imagine-image',
            'image_engine' => '',
            'image_human_safe_engine' => '',
            'image_high_quality_engine' => '',
            'image_quality_level' => 'standard',
            'image_aspect' => 'square',
            'image_steps' => '30',
            'image_cfg' => '7',
            'openai_image_quality' => 'auto',
            'images_per_pattern' => '4',
            'image_quality_gate_enabled' => '1',
            'image_quality_min_width' => '512',
            'image_quality_min_height' => '512',
            'image_quality_duplicate_distance' => '6',
            'image_quality_max_regeneration_attempts' => '2',
            'image_quality_vision_check_enabled' => '0',
            'image_quality_vision_model' => 'gpt-4o-mini',
            'image_quality_vision_min_score' => '0.55',
            'image_quality_reject_people_when_forbidden' => '1',
            'prompt_provider' => 'openai',
            'prompt_model' => 'haiku',
            'ng_words' => '',
            'photo_illustration_enabled' => '1',
            'photo_illustration_intro' => '',
            'photo_illustration_consent' => '',
            'photo_illustration_retention_days' => '14',
            'photo_illustration_size' => '1024x1024',
            'photo_illustration_styles' => '',
            'line_monthly_limit' => '5000',
            'daily_request_limit' => '2',
            'max_daily_requests_per_user' => '2',
            'max_images_per_request' => '4',
            'class_mode_enabled' => '1',
            'workflow_approval_mode' => 'manual',
            'workflow_attendance_gate' => 'approved_and_time_window',
            'first_visit_free_enabled' => '1',
            'survey_session_ttl_minutes' => '30',
            'generation_access_mode' => 'class_attendance',
            'generation_online_enabled' => '0',
            'generation_window_start' => '',
            'generation_window_end' => '',
            'generation_available_date_start' => '',
            'generation_available_date_end' => '',
            'generation_available_weekdays' => '',
            'generation_period_request_limit' => '',
            'generation_window_message' => '',
            'generation_stale_minutes' => '10',
            'worker_last_run' => '',
        ];
    }

    private static function detectTenant(PDO $pdo): ?array {
        if (self::$runtimeTenantId !== null) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM tenants
                WHERE id = ? AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([self::$runtimeTenantId]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        $adminTenantKey = self::adminSessionTenantKey();
        if ($adminTenantKey !== '') {
            $stmt = $pdo->prepare("
                SELECT *
                FROM tenants
                WHERE tenant_key = ? AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$adminTenantKey]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        $tenantKey = self::requestTenantKey();
        if ($tenantKey !== '') {
            $stmt = $pdo->prepare("
                SELECT *
                FROM tenants
                WHERE tenant_key = ? AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$tenantKey]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);

        if ($host !== '') {
            $stmt = $pdo->prepare("
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

        $stmt = $pdo->query("
            SELECT *
            FROM tenants
            WHERE is_default = 1 AND status = 'active'
            ORDER BY id ASC
            LIMIT 1
        ");
        $row = $stmt ? $stmt->fetch() : false;
        return $row ?: null;
    }

    private static function adminSessionTenantKey(): string {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (strpos($path, '/admin') !== 0) {
            return '';
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['admin_user_id']) && empty($_SESSION['admin_id'])) {
            return '';
        }
        $key = strtolower(trim((string)($_SESSION['admin_tenant_key'] ?? '')));
        $key = preg_replace('/[^a-z0-9_-]+/', '-', $key);
        $key = trim((string)$key, '-_');
        return $key !== '' ? substr($key, 0, 80) : '';
    }

    private static function requestTenantKey(): string {
        $candidates = [
            $_GET['tenant'] ?? '',
            $_GET['client'] ?? '',
            $_GET['tenant_key'] ?? '',
            $_SERVER['HTTP_X_AIART_TENANT'] ?? '',
            $_SERVER['HTTP_X_TENANT_KEY'] ?? '',
        ];

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (preg_match('#^/(?:webhook/line|stripe/webhook|shopping/webhook)/([A-Za-z0-9_-]+)$#', $path, $m)) {
            $candidates[] = $m[1];
        }

        foreach ($candidates as $value) {
            $key = strtolower(trim((string)$value));
            $key = preg_replace('/[^a-z0-9_-]+/', '-', $key);
            $key = trim((string)$key, '-_');
            if ($key !== '') {
                return substr($key, 0, 80);
            }
        }
        return '';
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        $postToken = $_POST['csrf_token'] ?? '';
        if (!$sessionToken || !hash_equals($sessionToken, $postToken)) {
            http_response_code(403);
            exit('Invalid CSRF token');
        }
    }
}
