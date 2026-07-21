<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class TicketLog {
    public static function ensureTable(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ticket_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id BIGINT UNSIGNED NULL,
                user_id INT NOT NULL,
                line_user_id VARCHAR(255) NULL,
                change_count INT NOT NULL,
                balance_after INT NULL,
                reason VARCHAR(50) NOT NULL,
                related_attendance_id INT NULL,
                related_payment_id INT NULL,
                memo TEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_ticket_log_tenant (tenant_id),
                INDEX idx_user (user_id),
                INDEX idx_reason (reason),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::ensureTenantColumn($pdo, 'ticket_logs', 'idx_ticket_log_tenant');
    }

    public static function record(PDO $pdo, array $data): void {
        try {
            self::ensureTable($pdo);
            $tenant = new TenantScopeService($pdo);
            $userId = (int)($data['user_id'] ?? 0);
            if ($userId <= 0) return;

            $lineUserId = $data['line_user_id'] ?? null;
            $balanceAfter = $data['balance_after'] ?? null;
            if ($lineUserId === null || $balanceAfter === null) {
                $stmt = $pdo->prepare("SELECT line_user_id, ticket_balance FROM users WHERE id = ?" . $tenant->andWhere('users'));
                $stmt->execute(array_merge([$userId], $tenant->params('users')));
                $user = $stmt->fetch() ?: [];
                $lineUserId = $lineUserId ?? ($user['line_user_id'] ?? null);
                $balanceAfter = $balanceAfter ?? ($user['ticket_balance'] ?? null);
            }

            [$columns, $values] = $tenant->assignInsert(
                'ticket_logs',
                ['user_id', 'line_user_id', 'change_count', 'balance_after', 'reason', 'related_attendance_id', 'related_payment_id', 'memo'],
                [
                    $userId,
                    $lineUserId,
                    (int)($data['change_count'] ?? 0),
                    $balanceAfter === null ? null : (int)$balanceAfter,
                    $data['reason'] ?? 'manual',
                    $data['related_attendance_id'] ?? null,
                    $data['related_payment_id'] ?? null,
                    $data['memo'] ?? null,
                ]
            );
            $columnSql = implode(', ', array_map(static fn($column) => '`' . $column . '`', $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $pdo->prepare("INSERT INTO ticket_logs ({$columnSql}, created_at) VALUES ({$placeholders}, NOW())");
            $stmt->execute($values);
        } catch (Throwable $e) {
            // Ticket logging must not block payments or reservations.
        }
    }

    public static function recent(PDO $pdo, int $limit = 200, int $userId = 0, string $reason = ''): array {
        self::ensureTable($pdo);
        $tenant = new TenantScopeService($pdo);
        $where = [];
        $params = [];
        $tenantWhere = $tenant->where('ticket_logs', 'l');
        if ($tenantWhere !== '') {
            $where[] = $tenantWhere;
            $params = array_merge($params, $tenant->params('ticket_logs'));
        }
        if ($userId > 0) {
            $where[] = 'l.user_id = ?';
            $params[] = $userId;
        }
        if ($reason !== '') {
            $where[] = 'l.reason = ?';
            $params[] = $reason;
        }
        $joinTenant = $tenant->active() && $tenant->hasTenantColumn('users')
            ? ' AND u.tenant_id = ' . (int)$tenant->tenantId()
            : '';
        $sql = "SELECT l.*, u.display_name FROM ticket_logs l
                LEFT JOIN users u ON u.id = l.user_id{$joinTenant}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.id DESC LIMIT ?';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $value) $stmt->bindValue($i + 1, $value);
        $stmt->bindValue(count($params) + 1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function summary(PDO $pdo): array {
        self::ensureTable($pdo);
        $tenant = new TenantScopeService($pdo);
        $where = $tenant->where('ticket_logs');
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN change_count > 0 THEN change_count ELSE 0 END),0) AS total_added,
                COALESCE(SUM(CASE WHEN change_count < 0 THEN ABS(change_count) ELSE 0 END),0) AS total_used,
                COUNT(*) AS total_logs
            FROM ticket_logs" . ($where !== '' ? ' WHERE ' . $where : ''));
        $stmt->execute($tenant->params('ticket_logs'));
        return $stmt->fetch() ?: [];
    }

    private static function ensureTenantColumn(PDO $pdo, string $table, string $index): void {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'
            ");
            $stmt->execute([$table]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id");
            }
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
            ");
            $stmt->execute([$table, $index]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` (tenant_id)");
            }
        } catch (Throwable $e) {
            // Existing installations are also repaired by TenantDataService.
        }
    }
}
