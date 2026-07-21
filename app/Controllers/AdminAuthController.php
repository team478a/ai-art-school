<?php
require_once BASE_PATH . '/config/app.php';
require_once BASE_PATH . '/config/database.php';

class AdminAuthController {
    public static function isLoggedIn(): bool {
        return !empty($_SESSION['admin_id']);
    }

    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            header('Location: /admin/login');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !self::isCsrfExemptPath()) {
            verify_csrf();
        }

        self::enforcePathPermission();
    }

    private static function isCsrfExemptPath(): bool {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        return in_array($path, ['/admin/update/upload', '/admin/update/rollback', '/admin/update'], true);
    }

    public static function requireOwner(): void {
        self::requireRole(['super_owner', 'owner']);
    }

    public static function requireAdmin(): void {
        self::requireRole(['super_owner', 'owner', 'admin']);
    }

    public static function requireRole(array $roles): void {
        if (!self::isLoggedIn()) {
            header('Location: /admin/login');
            exit;
        }
        if (!in_array(self::role(), $roles, true)) {
            self::deny();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !self::isCsrfExemptPath()) {
            verify_csrf();
        }
    }

    public static function role(): string {
        $role = $_SESSION['admin_role'] ?? 'staff';
        return in_array($role, ['super_owner', 'owner', 'admin', 'staff'], true) ? $role : 'staff';
    }

    public static function roleLabel(?string $role = null): string {
        $role = $role ?? self::role();
        if ($role === 'super_owner') return 'Super Owner';
        if ($role === 'owner') return 'Owner';
        if ($role === 'admin') return 'Admin';
        return 'Staff';
    }

    public static function isSuperOwner(): bool {
        return self::role() === 'super_owner';
    }

    public static function isOwner(): bool {
        return in_array(self::role(), ['super_owner', 'owner'], true);
    }

    public static function isAdmin(): bool {
        return in_array(self::role(), ['super_owner', 'owner', 'admin'], true);
    }

    public static function can(string $permission): bool {
        $role = self::role();
        if ($role === 'super_owner' || $role === 'owner') {
            return true;
        }

        $adminPermissions = [
            'classes', 'calendar', 'attendance', 'reservations', 'qrcode',
            'users', 'payments', 'tickets', 'cancellations', 'reports', 'exports',
            'broadcast', 'gallery', 'image_requests', 'gacha',
        ];
        $staffPermissions = [
            'classes', 'calendar', 'attendance', 'reservations', 'qrcode', 'manual',
        ];

        if ($role === 'admin') {
            return in_array($permission, $adminPermissions, true) || in_array($permission, $staffPermissions, true);
        }
        return in_array($permission, $staffPermissions, true);
    }

    public static function adminName(): string {
        return $_SESSION['admin_name'] ?? ($_SESSION['admin_email'] ?? '管理者');
    }

    private static function enforcePathPermission(): void {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (self::isTenantProxyMode() && self::isTenantProxyHiddenPath($path)) {
            header('Location: /admin/dashboard?tenant_menu=restricted');
            exit;
        }
        $permission = self::permissionForPath($path);
        if ($permission === '__owner_only__' && !self::isOwner()) {
            self::deny();
        }
        if ($permission !== '' && $permission !== '__owner_only__' && !self::can($permission)) {
            self::deny();
        }
    }

    private static function permissionForPath(string $path): string {
        $ownerOnly = [
            '/admin/settings', '/admin/settings/test', '/admin/update', '/admin/update/upload', '/admin/update/rollback',
            '/admin/managers', '/admin/line-config', '/admin/logs', '/admin/backup',
            '/admin/public-settings', '/admin/gacha-settings', '/admin/client-setup', '/admin/richmenu-segments',
            '/admin/tenants',
        ];
        foreach ($ownerOnly as $prefix) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return '__owner_only__';
            }
        }

        $map = [
            '/admin/classes' => 'classes',
            '/admin/calendar' => 'calendar',
            '/admin/attendance' => 'attendance',
            '/admin/reservations' => 'reservations',
            '/admin/qrcode' => 'qrcode',
            '/admin/manual' => 'manual',
            '/admin/users' => 'users',
            '/admin/payments' => 'payments',
            '/admin/tickets' => 'tickets',
            '/admin/cancellations' => 'cancellations',
            '/admin/report' => 'reports',
            '/admin/export' => 'exports',
            '/admin/broadcast' => 'broadcast',
            '/admin/gallery' => 'gallery',
            '/admin/image-requests' => 'image_requests',
            '/admin/gacha' => 'gacha',
        ];
        foreach ($map as $prefix => $permission) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return $permission;
            }
        }
        return '';
    }

    private static function isTenantProxyMode(): bool {
        $key = strtolower(trim((string)($_SESSION['admin_tenant_key'] ?? '')));
        return $key !== '' && $key !== 'default';
    }

    private static function isTenantProxyHiddenPath(string $path): bool {
        $hidden = [
            '/admin/users',
        ];
        foreach ($hidden as $prefix) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    private static function deny(): void {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>権限がありません</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;color:#444"><h1>権限がありません</h1><p>この操作には必要な管理権限がありません。</p><p><a href="/admin/dashboard">ダッシュボードへ戻る</a></p></body></html>';
        exit;
    }

    public function showLogin(): void {
        if (self::isLoggedIn()) {
            header('Location: /admin/dashboard');
            exit;
        }
        require BASE_PATH . '/app/Views/admin/login.php';
    }

    public function login(): void {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $error = null;

        if (!$email || !$password) {
            $error = 'メールアドレスとパスワードを入力してください';
        } else {
            try {
                $pdo = get_pdo();
                self::ensureColumns($pdo);
                self::ensureLoginLogsTable($pdo);

                $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE email = ?');
                $stmt->execute([$email]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($password, $admin['password_hash'])) {
                    if (($admin['status'] ?? 'active') !== 'active') {
                        self::recordLoginLog($pdo, null, $email, 'failed', 'suspended');
                        $error = 'このアカウントは停止されています';
                        require BASE_PATH . '/app/Views/admin/login.php';
                        return;
                    }
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_name'] = $admin['name'] ?? $admin['email'];
                    $_SESSION['admin_role'] = in_array(($admin['role'] ?? 'staff'), ['super_owner', 'owner', 'admin', 'staff'], true) ? $admin['role'] : 'staff';
                    self::setAdminTenantSession($pdo, $admin);

                    $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([$admin['id']]);
                    self::recordLoginLog($pdo, (int)$admin['id'], (string)$admin['email'], 'success', '');
                    header('Location: /admin/dashboard');
                    exit;
                }

                self::recordLoginLog($pdo, null, $email, 'failed', 'invalid_credentials');
                $error = 'メールアドレスまたはパスワードが違います';
            } catch (\Throwable $e) {
                $error = 'ログインに失敗しました: ' . $e->getMessage();
            }
        }

        require BASE_PATH . '/app/Views/admin/login.php';
    }

    public function logout(): void {
        try {
            $pdo = get_pdo();
            self::ensureLoginLogsTable($pdo);
            self::recordLoginLog(
                $pdo,
                isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null,
                (string)($_SESSION['admin_email'] ?? ''),
                'logout',
                ''
            );
        } catch (\Throwable $e) {
            // ログアウト自体を止めないため、記録失敗は握りつぶします。
        }

        $_SESSION = [];
        session_destroy();
        header('Location: /admin/login');
        exit;
    }

    public static function ensureColumns(PDO $pdo): void {
        $cols = $pdo->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('name', $cols, true)) {
            $pdo->exec('ALTER TABLE admin_users ADD COLUMN name VARCHAR(255) AFTER email');
        }
        if (!in_array('role', $cols, true)) {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'staff'");
            $pdo->exec("UPDATE admin_users SET role='owner' WHERE id=(SELECT t.mid FROM (SELECT MIN(id) AS mid FROM admin_users) AS t)");
        }
        if (!in_array('status', $cols, true)) {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
        }
        if (!in_array('last_login_at', $cols, true)) {
            $pdo->exec('ALTER TABLE admin_users ADD COLUMN last_login_at DATETIME');
        }
        if (!in_array('tenant_id', $cols, true)) {
            $pdo->exec('ALTER TABLE admin_users ADD COLUMN tenant_id INT NULL AFTER role');
        }
        self::ensureAdminTenantIndex($pdo);
    }

    private static function ensureAdminTenantIndex(PDO $pdo): void {
        try {
            $stmt = $pdo->query("SHOW INDEX FROM admin_users WHERE Key_name='idx_admin_users_tenant_id'");
            if (!$stmt || !$stmt->fetch()) {
                $pdo->exec('ALTER TABLE admin_users ADD INDEX idx_admin_users_tenant_id (tenant_id)');
            }
        } catch (\Throwable $e) {
            // Index creation is helpful for SaaS filtering, but login should not stop if the host rejects it.
        }
    }

    private static function setAdminTenantSession(PDO $pdo, array $admin): void {
        unset($_SESSION['admin_tenant_id'], $_SESSION['admin_tenant_key'], $_SESSION['admin_tenant_name']);

        $role = (string)($admin['role'] ?? 'staff');
        if (in_array($role, ['super_owner', 'owner'], true)) {
            return;
        }

        $tenantId = (int)($admin['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, tenant_key, name FROM tenants WHERE id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$tenantId]);
            $tenant = $stmt->fetch();
            if (!$tenant) {
                return;
            }

            $_SESSION['admin_tenant_id'] = (int)$tenant['id'];
            $_SESSION['admin_tenant_key'] = (string)$tenant['tenant_key'];
            $_SESSION['admin_tenant_name'] = (string)$tenant['name'];
        } catch (\Throwable $e) {
            unset($_SESSION['admin_tenant_id'], $_SESSION['admin_tenant_key'], $_SESSION['admin_tenant_name']);
        }
    }

    public static function ensureLoginLogsTable(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_login_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NULL,
                tenant_id INT NULL,
                tenant_key VARCHAR(80) NULL,
                email VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL,
                reason VARCHAR(80) NULL,
                ip_address VARCHAR(64) NULL,
                user_agent TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin_login_logs_created_at (created_at),
                INDEX idx_admin_login_logs_admin_id (admin_id),
                INDEX idx_admin_login_logs_tenant_id (tenant_id),
                INDEX idx_admin_login_logs_tenant_key (tenant_key),
                INDEX idx_admin_login_logs_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::ensureLoginLogTenantColumns($pdo);
    }

    private static function ensureLoginLogTenantColumns(PDO $pdo): void {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM admin_login_logs')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tenant_id', $cols, true)) {
                $pdo->exec('ALTER TABLE admin_login_logs ADD COLUMN tenant_id INT NULL AFTER admin_id');
            }
            if (!in_array('tenant_key', $cols, true)) {
                $pdo->exec('ALTER TABLE admin_login_logs ADD COLUMN tenant_key VARCHAR(80) NULL AFTER tenant_id');
            }
            self::ensureLoginLogIndex($pdo, 'idx_admin_login_logs_tenant_id', 'tenant_id');
            self::ensureLoginLogIndex($pdo, 'idx_admin_login_logs_tenant_key', 'tenant_key');
        } catch (\Throwable $e) {
            // 監査用の補助列なので、作成に失敗してもログイン処理は止めません。
        }
    }

    private static function ensureLoginLogIndex(PDO $pdo, string $indexName, string $columnName): void {
        try {
            $stmt = $pdo->query("SHOW INDEX FROM admin_login_logs WHERE Key_name=" . $pdo->quote($indexName));
            if (!$stmt || !$stmt->fetch()) {
                $pdo->exec('ALTER TABLE admin_login_logs ADD INDEX ' . $indexName . ' (' . $columnName . ')');
            }
        } catch (\Throwable $e) {
            // インデックス作成に失敗してもログ記録自体は継続します。
        }
    }

    private static function recordLoginLog(PDO $pdo, ?int $adminId, string $email, string $status, string $reason): void {
        try {
            self::ensureLoginLogsTable($pdo);
            $stmt = $pdo->prepare("
                INSERT INTO admin_login_logs (admin_id, tenant_id, tenant_key, email, status, reason, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $adminId,
                isset($_SESSION['admin_tenant_id']) ? (int)$_SESSION['admin_tenant_id'] : null,
                $_SESSION['admin_tenant_key'] ?? null,
                $email !== '' ? $email : null,
                $status,
                $reason !== '' ? $reason : null,
                self::clientIp(),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
            ]);
        } catch (\Throwable $e) {
            // ログ記録失敗でログイン操作を止めないため、例外は握りつぶします。
        }
    }

    private static function clientIp(): string {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $ip) {
            $ip = trim(explode(',', (string)$ip)[0]);
            if ($ip !== '') {
                return substr($ip, 0, 64);
            }
        }
        return '';
    }
}
