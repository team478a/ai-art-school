<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/TenantService.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class AdminReportController {
    private PDO $pdo;
    private TenantService $tenants;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenants = new TenantService($this->pdo);
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function stats(): void {
        $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
        $start = $month . '-01';
        $end = date('Y-m-d', strtotime($start . ' +1 month'));

        $summary = [
            'total_users' => $this->countValue("SELECT COUNT(*) FROM users WHERE 1=1" . $this->tenant->andWhere('users'), $this->tenant->params('users')),
            'active_users' => $this->countValue("SELECT COUNT(*) FROM users WHERE COALESCE(status, 'active') = 'active'" . $this->tenant->andWhere('users'), $this->tenant->params('users')),
            'subscribers' => $this->countValue("SELECT COUNT(*) FROM users WHERE member_type IN ('subscriber','subscription','subscribed','monthly')" . $this->tenant->andWhere('users'), $this->tenant->params('users')),
            'ticket_balance' => $this->countValue("SELECT COALESCE(SUM(ticket_balance), 0) FROM users WHERE 1=1" . $this->tenant->andWhere('users'), $this->tenant->params('users')),
            'month_classes' => $this->countValue("SELECT COUNT(*) FROM class_schedules WHERE class_date >= ? AND class_date < ?" . $this->tenant->andWhere('class_schedules'), array_merge([$start, $end], $this->tenant->params('class_schedules'))),
            'month_reserved' => $this->countValue("SELECT COUNT(*) FROM class_attendances WHERE created_at >= ? AND created_at < ?" . $this->tenant->andWhere('class_attendances'), array_merge([$start, $end], $this->tenant->params('class_attendances'))),
            'month_attended' => $this->countValue("SELECT COUNT(*) FROM class_attendances WHERE attended_at >= ? AND attended_at < ?" . $this->tenant->andWhere('class_attendances'), array_merge([$start, $end], $this->tenant->params('class_attendances'))),
            'month_requests' => $this->countValue("SELECT COUNT(*) FROM image_requests WHERE created_at >= ? AND created_at < ?" . $this->tenant->andWhere('image_requests'), array_merge([$start, $end], $this->tenant->params('image_requests'))),
            'month_images' => $this->countValue("SELECT COUNT(*) FROM generated_images WHERE created_at >= ? AND created_at < ?" . $this->tenant->andWhere('generated_images'), array_merge([$start, $end], $this->tenant->params('generated_images'))),
            'month_sales' => $this->countValue("SELECT COALESCE(SUM(amount), 0) FROM payment_logs WHERE status IN ('paid','succeeded') AND created_at >= ? AND created_at < ?" . $this->tenant->andWhere('payment_logs'), array_merge([$start, $end], $this->tenant->params('payment_logs'))),
        ];

        $attendanceJoinScope = $this->tenant->hasTenantColumn('class_attendances') && $this->tenant->tenantId()
            ? ' AND a.tenant_id = ' . (int)$this->tenant->tenantId()
            : '';
        $classStats = $this->fetchAll("
            SELECT s.id, s.class_date, s.title, s.capacity,
                   COUNT(a.id) AS reserved_count,
                   SUM(CASE WHEN a.status IN ('approved','attended') THEN 1 ELSE 0 END) AS approved_count,
                   SUM(CASE WHEN a.attended_at IS NOT NULL OR a.status = 'attended' THEN 1 ELSE 0 END) AS attended_count,
                   SUM(CASE WHEN a.payment_status IN ('paid','subscription','ticket') THEN 1 ELSE 0 END) AS paid_count,
                   COALESCE(SUM(CASE WHEN a.payment_status = 'paid' THEN a.payment_amount ELSE 0 END), 0) AS paid_amount
            FROM class_schedules s
            LEFT JOIN class_attendances a ON a.schedule_id = s.id{$attendanceJoinScope}
            WHERE s.class_date >= ? AND s.class_date < ?" . $this->tenant->andWhere('class_schedules', 's') . "
            GROUP BY s.id, s.class_date, s.title, s.capacity
            ORDER BY s.class_date ASC, s.start_time ASC
        ", array_merge([$start, $end], $this->tenant->params('class_schedules')));

        $monthlyRows = $this->fetchAll("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS requests
            FROM image_requests
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . $this->tenant->andWhere('image_requests') . "
            GROUP BY ym
            ORDER BY ym ASC
        ", $this->tenant->params('image_requests'));

        $salesRows = $this->fetchAll("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                   COALESCE(SUM(CASE WHEN status IN ('paid','succeeded') THEN amount ELSE 0 END), 0) AS sales,
                   COUNT(*) AS payments
            FROM payment_logs
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . $this->tenant->andWhere('payment_logs') . "
            GROUP BY ym
            ORDER BY ym ASC
        ", $this->tenant->params('payment_logs'));

        $attendanceRows = $this->fetchAll("
            SELECT DATE_FORMAT(s.class_date, '%Y-%m') AS ym,
                   COUNT(a.id) AS reservations,
                   SUM(CASE WHEN a.attended_at IS NOT NULL OR a.status = 'attended' THEN 1 ELSE 0 END) AS attended
            FROM class_schedules s
            LEFT JOIN class_attendances a ON a.schedule_id = s.id{$attendanceJoinScope}
            WHERE s.class_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . $this->tenant->andWhere('class_schedules', 's') . "
            GROUP BY ym
            ORDER BY ym ASC
        ", $this->tenant->params('class_schedules'));

        require BASE_PATH . '/app/Views/admin/report_stats.php';
    }

    public function logs(): void {
        $this->ensureLoginLogsTable();
        $tenants = $this->tenants->all();
        $selectedTenantId = max(0, (int)($_GET['tenant_id'] ?? 0));
        $where = '';
        $params = [];
        if ($selectedTenantId > 0) {
            $where = 'WHERE l.tenant_id = ?';
            $params[] = $selectedTenantId;
        }

        $loginLogs = $this->fetchAll("
            SELECT l.*, a.name AS admin_name, a.role AS admin_role,
                   t.name AS tenant_name, t.tenant_key AS tenant_key_display
            FROM admin_login_logs l
            LEFT JOIN admin_users a ON a.id = l.admin_id
            LEFT JOIN tenants t ON t.id = l.tenant_id
            {$where}
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT 200
        ", $params);

        $logs = [];
        $candidates = [
            STORAGE_PATH . '/logs/app.log',
            STORAGE_PATH . '/logs/audit.log',
            STORAGE_PATH . '/app.log',
        ];

        foreach ($candidates as $logFile) {
            if (!is_file($logFile)) continue;
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice(array_reverse($lines), 0, 200) as $line) {
                $logs[] = basename($logFile) . ' : ' . $line;
            }
        }

        require BASE_PATH . '/app/Views/admin/report_logs.php';
    }

    private function ensureLoginLogsTable(): void {
        $this->pdo->exec("
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
        $this->ensureLoginLogTenantColumns();
    }

    private function ensureLoginLogTenantColumns(): void {
        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM admin_login_logs')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tenant_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE admin_login_logs ADD COLUMN tenant_id INT NULL AFTER admin_id');
            }
            if (!in_array('tenant_key', $cols, true)) {
                $this->pdo->exec('ALTER TABLE admin_login_logs ADD COLUMN tenant_key VARCHAR(80) NULL AFTER tenant_id');
            }
            $this->ensureLoginLogIndex('idx_admin_login_logs_tenant_id', 'tenant_id');
            $this->ensureLoginLogIndex('idx_admin_login_logs_tenant_key', 'tenant_key');
        } catch (Throwable $e) {
            // ログ画面を止めないため、補助列の作成失敗は握りつぶします。
        }
    }

    private function ensureLoginLogIndex(string $indexName, string $columnName): void {
        try {
            $stmt = $this->pdo->query("SHOW INDEX FROM admin_login_logs WHERE Key_name=" . $this->pdo->quote($indexName));
            if (!$stmt || !$stmt->fetch()) {
                $this->pdo->exec('ALTER TABLE admin_login_logs ADD INDEX ' . $indexName . ' (' . $columnName . ')');
            }
        } catch (Throwable $e) {
            // インデックス作成に失敗しても表示は継続します。
        }
    }

    private function countValue(string $sql, array $params = []): int {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function fetchAll(string $sql, array $params = []): array {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
