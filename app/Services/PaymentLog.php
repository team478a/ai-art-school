<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class PaymentLog {
    public static function ensureTable(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id BIGINT UNSIGNED NULL,
                user_id INT NULL,
                line_user_id VARCHAR(255) NULL,
                kind VARCHAR(30) NOT NULL,
                amount INT NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'paid',
                description VARCHAR(255) NULL,
                stripe_session_id VARCHAR(255) NULL,
                stripe_payment_intent VARCHAR(255) NULL,
                refunded_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_payment_transaction_tenant (tenant_id),
                INDEX idx_user (user_id),
                INDEX idx_created (created_at),
                UNIQUE KEY uq_stripe_session_id (stripe_session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::ensureTenantColumn($pdo, 'payment_transactions', 'idx_payment_transaction_tenant');
    }

    public static function existsByStripeSessionId(string $sessionId): bool {
        if ($sessionId === '') return false;
        $pdo = get_pdo();
        self::ensureTable($pdo);
        $tenant = new TenantScopeService($pdo);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE stripe_session_id = ?" . $tenant->andWhere('payment_transactions'));
        $stmt->execute(array_merge([$sessionId], $tenant->params('payment_transactions')));
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function record(array $data): int {
        $pdo = get_pdo();
        self::ensureTable($pdo);
        $tenant = new TenantScopeService($pdo);
        [$columns, $values] = $tenant->assignInsert(
            'payment_transactions',
            ['user_id', 'line_user_id', 'kind', 'amount', 'status', 'description', 'stripe_session_id', 'stripe_payment_intent'],
            [
                $data['user_id'] ?? null,
                $data['line_user_id'] ?? null,
                $data['kind'] ?? 'attendance',
                (int)($data['amount'] ?? 0),
                $data['status'] ?? 'paid',
                $data['description'] ?? '',
                $data['stripe_session_id'] ?? null,
                $data['stripe_payment_intent'] ?? null,
            ]
        );
        $columnSql = implode(', ', array_map(static fn($column) => '`' . $column . '`', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare("INSERT INTO payment_transactions ({$columnSql}, created_at) VALUES ({$placeholders}, NOW())");
        $stmt->execute($values);
        return (int)$pdo->lastInsertId();
    }

    public static function markRefunded(PDO $pdo, int $id): void {
        $tenant = new TenantScopeService($pdo);
        $pdo->prepare("UPDATE payment_transactions SET status='refunded', refunded_at=NOW() WHERE id=?" . $tenant->andWhere('payment_transactions'))
            ->execute(array_merge([$id], $tenant->params('payment_transactions')));
    }

    public static function markRefundedBySessionId(PDO $pdo, string $sessionId): void {
        if ($sessionId === '') return;
        self::ensureTable($pdo);
        $tenant = new TenantScopeService($pdo);
        $pdo->prepare("UPDATE payment_transactions SET status='refunded', refunded_at=NOW() WHERE stripe_session_id=? AND status='paid'" . $tenant->andWhere('payment_transactions'))
            ->execute(array_merge([$sessionId], $tenant->params('payment_transactions')));
    }

    public static function recent(PDO $pdo, int $limit = 100, string $filter = '', string $status = ''): array {
        self::ensureTable($pdo);
        $tenant = new TenantScopeService($pdo);
        $where = [];
        $params = [];
        $tenantWhere = $tenant->where('payment_transactions', 'p');
        if ($tenantWhere !== '') {
            $where[] = $tenantWhere;
            $params = array_merge($params, $tenant->params('payment_transactions'));
        }
        if ($filter !== '') {
            $where[] = 'p.kind = ?';
            $params[] = $filter;
        }
        if ($status !== '') {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        $joinTenant = $tenant->active() && $tenant->hasTenantColumn('users')
            ? ' AND u.tenant_id = ' . (int)$tenant->tenantId()
            : '';
        $sql = "SELECT p.*, u.display_name FROM payment_transactions p
                LEFT JOIN users u ON u.id = p.user_id{$joinTenant}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.id DESC LIMIT ?';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $value) $stmt->bindValue($i + 1, $value);
        $stmt->bindValue(count($params) + 1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function summary(PDO $pdo): array {
        self::ensureTable($pdo);
        $tenant = new TenantScopeService($pdo);
        $where = $tenant->where('payment_transactions');
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS total_paid,
                COALESCE(SUM(CASE WHEN status='refunded' THEN amount ELSE 0 END),0) AS total_refunded,
                COUNT(CASE WHEN status='paid' THEN 1 END) AS count_paid,
                COALESCE(SUM(CASE WHEN status='paid' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN amount ELSE 0 END),0) AS month_paid
            FROM payment_transactions" . ($where !== '' ? ' WHERE ' . $where : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($tenant->params('payment_transactions'));
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
