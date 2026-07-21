<?php
require_once BASE_PATH . '/config/database.php';

class TenantUsageService {
    private PDO $pdo;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?: get_pdo();
    }

    public function summaries(array $tenants): array {
        $summaries = [];
        foreach ($tenants as $tenant) {
            $tenantId = (int)($tenant['id'] ?? 0);
            if ($tenantId > 0) {
                $summaries[$tenantId] = $this->summaryFor($tenant);
            }
        }
        return $summaries;
    }

    public function monthlyReport(array $tenants, string $month): array {
        $month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $start = $month . '-01 00:00:00';
        $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));

        $rows = [];
        $totals = [
            'users' => 0,
            'classes' => 0,
            'reservations' => 0,
            'approved' => 0,
            'attended' => 0,
            'image_requests' => 0,
            'completed_images' => 0,
            'failed_images' => 0,
            'line_messages' => 0,
            'payments' => 0,
            'payment_amount' => 0,
            'refunds' => 0,
            'refund_amount' => 0,
            'net_amount' => 0,
        ];

        foreach ($tenants as $tenant) {
            $tenantId = (int)($tenant['id'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }

            $payment = $this->paymentSummary($tenantId, $start, $end);
            $row = [
                'tenant' => $tenant,
                'users' => $this->countByTenantAndDate('users', $tenantId, $start, $end),
                'classes' => $this->countByTenantAndDate('class_schedules', $tenantId, $start, $end, ['class_date', 'created_at']),
                'reservations' => $this->countByTenantAndDate('class_attendances', $tenantId, $start, $end),
                'approved' => $this->countByTenantAndDate('class_attendances', $tenantId, $start, $end, ['created_at'], 'status = ?', ['approved']),
                'attended' => $this->countByTenantAndDate('class_attendances', $tenantId, $start, $end, ['attended_at', 'updated_at'], 'attended_at IS NOT NULL'),
                'image_requests' => $this->countByTenantAndDate('image_requests', $tenantId, $start, $end),
                'completed_images' => $this->countByTenantAndDate('image_requests', $tenantId, $start, $end, ['created_at'], 'status = ?', ['completed']),
                'failed_images' => $this->countByTenantAndDate('image_requests', $tenantId, $start, $end, ['created_at'], 'status = ?', ['failed']),
                'line_messages' => $this->lineMessageCount($tenantId, $start, $end),
                'payments' => $payment['payments'],
                'payment_amount' => $payment['payment_amount'],
                'refunds' => $payment['refunds'],
                'refund_amount' => $payment['refund_amount'],
                'net_amount' => $payment['payment_amount'] - $payment['refund_amount'],
                'latest_activity' => $this->latestActivity($tenantId),
            ];

            foreach ($totals as $key => $value) {
                if (isset($row[$key])) {
                    $totals[$key] += (int)$row[$key];
                }
            }
            $rows[] = $row;
        }

        return [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    private function summaryFor(array $tenant): array {
        $tenantId = (int)$tenant['id'];
        $users = $this->countByTenant('users', $tenantId);
        $classes = $this->countByTenant('class_schedules', $tenantId);
        $futureClasses = $this->countByTenant('class_schedules', $tenantId, 'class_date >= CURDATE()');
        $reservations = $this->countByTenant('class_attendances', $tenantId);
        $approved = $this->countByTenant('class_attendances', $tenantId, 'status = ?', ['approved']);
        $imageRequests = $this->countByTenant('image_requests', $tenantId);
        $completedImages = $this->countByTenant('image_requests', $tenantId, 'status = ?', ['completed']);
        $failedImages = $this->countByTenant('image_requests', $tenantId, 'status = ?', ['failed']);
        $payments = $this->countByTenant('payment_transactions', $tenantId);
        $lineMessages = $this->lineMessageCount($tenantId);

        $warnings = [];
        if (($tenant['status'] ?? '') !== 'active') {
            $warnings[] = (($tenant['status'] ?? '') === 'archived') ? 'アーカイブ' : '停止中';
        }
        if (trim((string)($tenant['primary_domain'] ?? '')) === '') {
            $warnings[] = 'ドメイン未設定';
        }
        if ($users === 0) {
            $warnings[] = 'ユーザーなし';
        }
        if ($futureClasses === 0) {
            $warnings[] = '今後の開催なし';
        }
        if ($failedImages > 0) {
            $warnings[] = '生成失敗あり';
        }

        return [
            'users' => $users,
            'classes' => $classes,
            'future_classes' => $futureClasses,
            'reservations' => $reservations,
            'approved' => $approved,
            'image_requests' => $imageRequests,
            'completed_images' => $completedImages,
            'failed_images' => $failedImages,
            'payments' => $payments,
            'line_messages' => $lineMessages,
            'latest_activity' => $this->latestActivity($tenantId),
            'warnings' => $warnings,
        ];
    }

    private function paymentSummary(int $tenantId, string $start, string $end): array {
        $summary = [
            'payments' => 0,
            'payment_amount' => 0,
            'refunds' => 0,
            'refund_amount' => 0,
        ];

        foreach (['payment_transactions', 'payment_logs'] as $table) {
            if (!$this->tableExists($table) || !$this->columnExists($table, 'tenant_id')) {
                continue;
            }
            $dateColumn = $this->firstExistingColumn($table, ['paid_at', 'created_at', 'updated_at']);
            if ($dateColumn === null) {
                continue;
            }
            $amountColumn = $this->firstExistingColumn($table, ['amount', 'amount_yen', 'total_amount', 'price']);
            $statusColumn = $this->firstExistingColumn($table, ['status', 'payment_status', 'type']);

            try {
                $sql = 'SELECT * FROM ' . $this->q($table) . ' WHERE tenant_id = ? AND ' . $this->q($dateColumn) . ' >= ? AND ' . $this->q($dateColumn) . ' < ?';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$tenantId, $start, $end]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $status = strtolower((string)($statusColumn ? ($row[$statusColumn] ?? '') : ''));
                    $amount = $amountColumn ? (int)($row[$amountColumn] ?? 0) : 0;
                    if (preg_match('/refund|refunded|返金/', $status)) {
                        $summary['refunds']++;
                        $summary['refund_amount'] += abs($amount);
                        continue;
                    }
                    if ($status === '' || preg_match('/paid|succeeded|complete|completed|success|支払|決済/', $status)) {
                        $summary['payments']++;
                        $summary['payment_amount'] += max(0, $amount);
                    }
                }
            } catch (Throwable $e) {
                continue;
            }
            break;
        }

        return $summary;
    }

    private function lineMessageCount(int $tenantId, ?string $start = null, ?string $end = null): int {
        foreach (['line_message_logs', 'line_messages', 'message_logs'] as $table) {
            if ($this->tableExists($table) && $this->columnExists($table, 'tenant_id')) {
                return $this->countByTenantAndDate($table, $tenantId, $start, $end);
            }
        }

        if ($this->tableExists('system_logs') && $this->columnExists('system_logs', 'tenant_id')) {
            $extra = '';
            $params = [];
            if ($this->columnExists('system_logs', 'category')) {
                $extra = 'category LIKE ?';
                $params[] = '%LINE%';
            } elseif ($this->columnExists('system_logs', 'message')) {
                $extra = 'message LIKE ?';
                $params[] = '%LINE%';
            }
            if ($start !== null && $end !== null) {
                return $this->countByTenantAndDate('system_logs', $tenantId, $start, $end, ['created_at'], $extra, $params);
            }
            return $this->countByTenant('system_logs', $tenantId, $extra, $params);
        }

        return 0;
    }

    private function latestActivity(int $tenantId): string {
        $candidates = [];
        foreach ([
            'users' => ['updated_at', 'created_at'],
            'class_schedules' => ['updated_at', 'created_at'],
            'class_attendances' => ['updated_at', 'created_at'],
            'image_requests' => ['updated_at', 'created_at'],
            'payment_transactions' => ['updated_at', 'created_at'],
        ] as $table => $columns) {
            if (!$this->tableExists($table) || !$this->columnExists($table, 'tenant_id')) {
                continue;
            }
            foreach ($columns as $column) {
                if (!$this->columnExists($table, $column)) {
                    continue;
                }
                try {
                    $stmt = $this->pdo->prepare(
                        'SELECT MAX(' . $this->q($column) . ') FROM ' . $this->q($table) . ' WHERE tenant_id = ?'
                    );
                    $stmt->execute([$tenantId]);
                    $value = (string)($stmt->fetchColumn() ?: '');
                    if ($value !== '') {
                        $candidates[] = $value;
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        if (empty($candidates)) {
            return '-';
        }
        rsort($candidates);
        return $candidates[0];
    }

    private function countByTenantAndDate(
        string $table,
        int $tenantId,
        ?string $start = null,
        ?string $end = null,
        array $dateColumns = ['created_at'],
        string $extraWhere = '',
        array $extraParams = []
    ): int {
        if (!$this->tableExists($table) || !$this->columnExists($table, 'tenant_id')) {
            return 0;
        }

        $dateColumn = ($start !== null && $end !== null) ? $this->firstExistingColumn($table, $dateColumns) : null;
        if ($start !== null && $end !== null && $dateColumn === null) {
            return 0;
        }

        try {
            $sql = 'SELECT COUNT(*) FROM ' . $this->q($table) . ' WHERE tenant_id = ?';
            $params = [$tenantId];
            if ($dateColumn !== null) {
                $sql .= ' AND ' . $this->q($dateColumn) . ' >= ? AND ' . $this->q($dateColumn) . ' < ?';
                $params[] = $start;
                $params[] = $end;
            }
            if ($extraWhere !== '') {
                $sql .= ' AND ' . $extraWhere;
                $params = array_merge($params, $extraParams);
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function countByTenant(string $table, int $tenantId, string $extraWhere = '', array $extraParams = []): int {
        if (!$this->tableExists($table) || !$this->columnExists($table, 'tenant_id')) {
            return 0;
        }

        try {
            $sql = 'SELECT COUNT(*) FROM ' . $this->q($table) . ' WHERE tenant_id = ?';
            $params = [$tenantId];
            if ($extraWhere !== '') {
                $sql .= ' AND ' . $extraWhere;
                $params = array_merge($params, $extraParams);
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function firstExistingColumn(string $table, array $columns): ?string {
        foreach ($columns as $column) {
            if ($this->columnExists($table, $column)) {
                return $column;
            }
        }
        return null;
    }

    private function tableExists(string $table): bool {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = ?
            ");
            $stmt->execute([$table]);
            $this->tableCache[$table] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $this->tableCache[$table] = false;
        }
        return $this->tableCache[$table];
    }

    private function columnExists(string $table, string $column): bool {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
            ");
            $stmt->execute([$table, $column]);
            $this->columnCache[$key] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $this->columnCache[$key] = false;
        }
        return $this->columnCache[$key];
    }

    private function q(string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
