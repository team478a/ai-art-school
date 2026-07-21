<?php

require_once BASE_PATH . '/config/database.php';

class TenantErrorMonitorService
{
    private PDO $pdo;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: get_pdo();
    }

    public function summaries(array $tenants): array
    {
        $summaries = [];
        foreach ($tenants as $tenant) {
            $tenantId = (int)($tenant['id'] ?? 0);
            if ($tenantId > 0) {
                $summaries[$tenantId] = $this->summary($tenantId);
            }
        }
        return $summaries;
    }

    public function summary(int $tenantId): array
    {
        $recent = array_merge(
            $this->recentImageErrors($tenantId),
            $this->recentJobErrors($tenantId),
            $this->recentPaymentErrors($tenantId),
            $this->recentSystemErrors($tenantId)
        );
        usort($recent, static function (array $a, array $b): int {
            return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        });

        $last24h = 0;
        $last7d = 0;
        $now = time();
        foreach ($recent as $row) {
            $timestamp = strtotime((string)($row['created_at'] ?? '')) ?: 0;
            if ($timestamp >= $now - 86400) {
                $last24h++;
            }
            if ($timestamp >= $now - 604800) {
                $last7d++;
            }
        }

        return [
            'last24h' => $last24h,
            'last7d' => $last7d,
            'latest' => $recent[0] ?? null,
            'recent' => array_slice($recent, 0, 5),
            'level' => $last24h >= 5 || $last7d >= 20
                ? 'danger'
                : (($last24h > 0 || $last7d >= 5) ? 'warning' : 'ok'),
        ];
    }

    private function recentImageErrors(int $tenantId): array
    {
        return $this->fetchStatusErrors(
            'image_requests',
            $tenantId,
            ['failed', 'error'],
            ['error_message', 'input_text', 'status'],
            '画像生成'
        );
    }

    private function recentJobErrors(int $tenantId): array
    {
        return $this->fetchStatusErrors(
            'job_queue',
            $tenantId,
            ['failed', 'error'],
            ['last_error', 'error_message', 'job_type', 'status'],
            '自動処理'
        );
    }

    private function recentPaymentErrors(int $tenantId): array
    {
        $rows = [];
        foreach (['payment_logs', 'payment_transactions'] as $table) {
            $rows = array_merge($rows, $this->fetchStatusErrors(
                $table,
                $tenantId,
                ['failed', 'error', 'payment_failed'],
                ['message', 'error_message', 'payment_type', 'status'],
                '決済'
            ));
        }
        return $rows;
    }

    private function recentSystemErrors(int $tenantId): array
    {
        if (!$this->canTenantQuery('system_logs') || !$this->columnExists('system_logs', 'id')) {
            return [];
        }
        $timeColumn = $this->firstExistingColumn('system_logs', ['created_at', 'updated_at']);
        $messageColumn = $this->firstExistingColumn('system_logs', ['message', 'log_type']);
        $levelColumn = $this->firstExistingColumn('system_logs', ['log_level', 'level']);
        if ($timeColumn === '' || $messageColumn === '' || $levelColumn === '') {
            return [];
        }

        try {
            $cutoff = date('Y-m-d H:i:s', time() - 604800);
            $sql = 'SELECT id, `' . $timeColumn . '` AS created_at, `'
                . $messageColumn . '` AS raw_message FROM system_logs'
                . ' WHERE tenant_id = ? AND `' . $levelColumn . '` IN (?, ?)'
                . ' AND `' . $timeColumn . '` >= ?'
                . ' ORDER BY `' . $timeColumn . '` DESC, id DESC LIMIT 20';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenantId, 'error', 'warning', $cutoff]);
            return $this->formatRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'システム');
        } catch (Throwable $e) {
            return [];
        }
    }

    private function fetchStatusErrors(
        string $table,
        int $tenantId,
        array $statuses,
        array $messageColumns,
        string $source
    ): array {
        if (!$this->canTenantQuery($table)
            || !$this->columnExists($table, 'id')
            || !$this->columnExists($table, 'status')) {
            return [];
        }
        $timeColumn = $this->firstExistingColumn($table, ['updated_at', 'created_at']);
        $messageColumn = $this->firstExistingColumn($table, $messageColumns);
        if ($timeColumn === '' || $messageColumn === '' || !$statuses) {
            return [];
        }

        try {
            $cutoff = date('Y-m-d H:i:s', time() - 604800);
            $marks = implode(',', array_fill(0, count($statuses), '?'));
            $sql = 'SELECT id, `' . $timeColumn . '` AS created_at, `'
                . $messageColumn . '` AS raw_message FROM `' . $table . '`'
                . ' WHERE tenant_id = ? AND status IN (' . $marks . ')'
                . ' AND `' . $timeColumn . '` >= ?'
                . ' ORDER BY `' . $timeColumn . '` DESC, id DESC LIMIT 20';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge([$tenantId], $statuses, [$cutoff]));
            return $this->formatRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $source);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function formatRows(array $rows, string $source): array
    {
        foreach ($rows as &$row) {
            $id = (int)($row['id'] ?? 0);
            $raw = trim((string)($row['raw_message'] ?? ''));
            $row['source'] = $source;
            $row['message'] = $source . ($id > 0 ? ' #' . $id : '') . ($raw !== '' ? ' ' . $raw : '');
            unset($row['raw_message']);
        }
        unset($row);
        return $rows;
    }

    private function canTenantQuery(string $table): bool
    {
        return $this->tableExists($table) && $this->columnExists($table, 'tenant_id');
    }

    private function firstExistingColumn(string $table, array $columns): string
    {
        foreach ($columns as $column) {
            if ($this->columnExists($table, $column)) {
                return $column;
            }
        }
        return '';
    }

    private function tableExists(string $table): bool
    {
        if (!array_key_exists($table, $this->tableCache)) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
                );
                $stmt->execute([$table]);
                $this->tableCache[$table] = (int)$stmt->fetchColumn() > 0;
            } catch (Throwable $e) {
                $this->tableCache[$table] = false;
            }
        }
        return $this->tableCache[$table];
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $this->columnCache)) {
            if (!$this->tableExists($table)) {
                return $this->columnCache[$key] = false;
            }
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
                );
                $stmt->execute([$table, $column]);
                $this->columnCache[$key] = (int)$stmt->fetchColumn() > 0;
            } catch (Throwable $e) {
                $this->columnCache[$key] = false;
            }
        }
        return $this->columnCache[$key];
    }
}
