<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class ReservationEventLog {
    public static function ensureTable(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reservation_event_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id BIGINT UNSIGNED NULL,
                attendance_id INT NULL,
                schedule_id INT NULL,
                user_id INT NULL,
                line_user_id VARCHAR(255) NULL,
                event_type VARCHAR(50) NOT NULL,
                payment_status VARCHAR(50) NULL,
                amount INT NOT NULL DEFAULT 0,
                message TEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_reservation_event_tenant (tenant_id),
                INDEX idx_event_type (event_type),
                INDEX idx_attendance (attendance_id),
                INDEX idx_schedule (schedule_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::ensureTenantColumn($pdo);
    }

    public static function record(PDO $pdo, array $data): void {
        try {
            self::ensureTable($pdo);
            $tenant = new TenantScopeService($pdo);
            [$columns, $values] = $tenant->assignInsert(
                'reservation_event_logs',
                ['attendance_id', 'schedule_id', 'user_id', 'line_user_id', 'event_type', 'payment_status', 'amount', 'message'],
                [
                    $data['attendance_id'] ?? null,
                    $data['schedule_id'] ?? null,
                    $data['user_id'] ?? null,
                    $data['line_user_id'] ?? null,
                    $data['event_type'] ?? 'unknown',
                    $data['payment_status'] ?? null,
                    (int)($data['amount'] ?? 0),
                    $data['message'] ?? null,
                ]
            );
            $columnSql = implode(', ', array_map(static fn($column) => '`' . $column . '`', $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $pdo->prepare("INSERT INTO reservation_event_logs ({$columnSql}, created_at) VALUES ({$placeholders}, NOW())");
            $stmt->execute($values);
        } catch (Throwable $e) {
            // Logging must never block reservations or cancellation.
        }
    }

    public static function recent(PDO $pdo, int $limit = 200, string $eventType = ''): array {
        self::ensureTable($pdo);
        $tenant = new TenantScopeService($pdo);
        $where = [];
        $params = [];
        $tenantWhere = $tenant->where('reservation_event_logs', 'l');
        if ($tenantWhere !== '') {
            $where[] = $tenantWhere;
            $params = array_merge($params, $tenant->params('reservation_event_logs'));
        }
        if ($eventType !== '') {
            $where[] = 'l.event_type = ?';
            $params[] = $eventType;
        }

        $sql = "
            SELECT l.*, u.display_name, s.title, s.class_date, s.start_time
            FROM reservation_event_logs l
            LEFT JOIN users u ON u.id = l.user_id" . self::joinTenantCondition($tenant, 'users', 'u') . "
            LEFT JOIN class_schedules s ON s.id = l.schedule_id" . self::joinTenantCondition($tenant, 'class_schedules', 's') . "
        ";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.id DESC LIMIT ?';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private static function joinTenantCondition(TenantScopeService $tenant, string $table, string $alias): string {
        if (!$tenant->active() || !$tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$tenant->tenantId();
    }

    private static function ensureTenantColumn(PDO $pdo): void {
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM reservation_event_logs")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tenant_id', $columns, true)) {
                $pdo->exec('ALTER TABLE reservation_event_logs ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id');
            }
            $indexes = $pdo->query("SHOW INDEX FROM reservation_event_logs")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($indexes, 'Key_name');
            if (!in_array('idx_reservation_event_tenant', $names, true)) {
                $pdo->exec('ALTER TABLE reservation_event_logs ADD INDEX idx_reservation_event_tenant (tenant_id)');
            }
        } catch (Throwable $e) {
            // TenantDataService also repairs the schema from the client management page.
        }
    }
}
