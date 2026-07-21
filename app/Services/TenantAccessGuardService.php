<?php

class TenantAccessGuardService {
    public static function blockIfSuspended(string $context = 'page'): void {
        try {
            if (!function_exists('get_pdo')) {
                return;
            }

            $pdo = get_pdo();
            $tenant = self::detectTenant($pdo);
            if (!$tenant) {
                return;
            }

            if ((string)($tenant['status'] ?? 'active') === 'active') {
                return;
            }

            self::respondSuspended($context, $tenant);
        } catch (Throwable $e) {
            // Do not break public pages if the tenant table is missing or being migrated.
            return;
        }
    }

    private static function detectTenant(PDO $pdo): ?array {
        self::ensureTables($pdo);

        $tenantKey = self::requestedTenantKey();
        if ($tenantKey !== '') {
            $tenant = self::findByKey($pdo, $tenantKey);
            if ($tenant) {
                return $tenant;
            }
        }

        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host);
        if ($host !== '') {
            $stmt = $pdo->prepare('SELECT * FROM tenants WHERE LOWER(primary_domain) = ? LIMIT 1');
            $stmt->execute([$host]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    private static function requestedTenantKey(): string {
        $candidates = [
            $_GET['tenant'] ?? '',
            $_GET['client'] ?? '',
            $_GET['tenant_key'] ?? '',
            $_POST['tenant'] ?? '',
            $_POST['client'] ?? '',
            $_POST['tenant_key'] ?? '',
            $_SERVER['HTTP_X_AIART_TENANT'] ?? '',
            $_SERVER['HTTP_X_TENANT_KEY'] ?? '',
        ];

        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        if (preg_match('#^/(?:webhook/line|stripe/webhook|shopping/webhook)/([A-Za-z0-9_-]+)$#', $path, $m)) {
            $candidates[] = $m[1];
        }

        foreach ($candidates as $candidate) {
            $key = self::normalizeTenantKey((string)$candidate);
            if ($key !== '') {
                return $key;
            }
        }

        return '';
    }

    private static function normalizeTenantKey(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
        $value = trim((string)$value, '-_');
        return substr($value, 0, 80);
    }

    private static function findByKey(PDO $pdo, string $tenantKey): ?array {
        $stmt = $pdo->prepare('SELECT * FROM tenants WHERE tenant_key = ? LIMIT 1');
        $stmt->execute([$tenantKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function ensureTables(PDO $pdo): void {
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
    }

    private static function respondSuspended(string $context, array $tenant): void {
        $message = self::text('\u3053\u306e\u30b5\u30fc\u30d3\u30b9\u306f\u73fe\u5728\u505c\u6b62\u4e2d\u3067\u3059\u3002\u7ba1\u7406\u8005\u3078\u304a\u554f\u3044\u5408\u308f\u305b\u304f\u3060\u3055\u3044\u3002');

        if ($context === 'webhook') {
            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'tenant suspended';
            exit;
        }

        if ($context === 'json' || self::expectsJson()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => false,
                'message' => $message,
                'tenant' => (string)($tenant['tenant_key'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        $name = trim((string)($tenant['service_name'] ?? '')) ?: trim((string)($tenant['name'] ?? self::text('\u30b5\u30fc\u30d3\u30b9')));
        $title = self::text('\u30b5\u30fc\u30d3\u30b9\u505c\u6b62\u4e2d');
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f7fb;color:#111827}.wrap{min-height:100vh;display:grid;place-items:center;padding:24px}.card{max-width:520px;background:#fff;border:1px solid #dfe5ef;border-radius:16px;padding:28px;box-shadow:0 10px 28px rgba(15,23,42,.08)}h1{font-size:24px;margin:0 0 12px}.name{color:#6d5df3;font-weight:800;margin-bottom:16px}.msg{line-height:1.8;color:#52607a}</style>';
        echo '</head><body><main class="wrap"><section class="card">';
        echo '<div class="name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        echo '<p class="msg">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</section></main></body></html>';
        exit;
    }

    private static function text(string $escaped): string {
        $decoded = json_decode('"' . $escaped . '"');
        return is_string($decoded) ? $decoded : $escaped;
    }

    private static function expectsJson(): bool {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        return strpos($accept, 'application/json') !== false || $xhr === 'xmlhttprequest';
    }
}
