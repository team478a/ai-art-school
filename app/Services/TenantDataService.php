<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/TenantService.php';

class TenantDataService {
    private PDO $pdo;
    private TenantService $tenants;
    private array $errors = [];

    private array $tenantTables = [
        'admin_users' => 'id',
        'users' => 'id',
        'class_schedules' => 'id',
        'class_attendances' => 'id',
        'user_sessions' => 'id',
        'image_requests' => 'id',
        'prompts' => 'id',
        'generated_images' => 'id',
        'job_queue' => 'id',
        'system_logs' => 'id',
        'payment_transactions' => 'id',
        'payment_logs' => 'id',
        'reservation_event_logs' => 'id',
        'ticket_logs' => 'id',
        'class_waitlists' => 'id',
        'image_request_usage_overrides' => 'id',
        'subscriptions' => 'id',
        'user_tickets' => 'id',
        'audit_logs' => 'id',
        'operation_logs' => 'id',
        'login_logs' => 'id',
        'admin_login_logs' => 'id',
        'class_notification_logs' => 'id',
        'class_followup_logs' => 'id',
        'gacha_campaigns' => 'id',
        'gacha_rarities' => 'id',
        'gacha_prizes' => 'id',
        'gacha_entitlements' => 'id',
        'gacha_entries' => 'id',
        'gacha_results' => 'id',
        'gacha_purchase_interests' => 'id',
    ];

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?: get_pdo();
        $this->tenants = new TenantService($this->pdo);
    }

    public function ensureDataColumns(): void {
        $defaultTenantId = $this->defaultTenantId();

        foreach ($this->tenantTables as $table => $afterColumn) {
            try {
                if (!$this->tableExists($table)) {
                    continue;
                }

                if (!$this->columnExists($table, 'tenant_id')) {
                    $afterSql = $this->columnExists($table, $afterColumn) ? ' AFTER ' . $this->quoteIdentifier($afterColumn) : '';
                    $this->pdo->exec(
                        'ALTER TABLE ' . $this->quoteIdentifier($table) .
                        ' ADD COLUMN tenant_id BIGINT UNSIGNED NULL' . $afterSql
                    );
                }

                $indexName = $this->indexName($table);
                if (!$this->indexExists($table, $indexName)) {
                    $this->pdo->exec(
                        'ALTER TABLE ' . $this->quoteIdentifier($table) .
                        ' ADD INDEX ' . $this->quoteIdentifier($indexName) . ' (tenant_id)'
                    );
                }

                if ($defaultTenantId > 0) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE ' . $this->quoteIdentifier($table) .
                        ' SET tenant_id = ? WHERE tenant_id IS NULL'
                    );
                    $stmt->execute([$defaultTenantId]);
                }
            } catch (Throwable $e) {
                $this->errors[$table] = $e->getMessage();
            }
        }

        $this->ensureTenantUniqueKey('users', ['tenant_id', 'line_user_id'], 'uniq_users_tenant_line');
        $this->ensureTenantUniqueKey('user_sessions', ['tenant_id', 'line_user_id'], 'uniq_sessions_tenant_line');
        $this->ensureTenantUniqueKey(
            'image_request_usage_overrides',
            ['tenant_id', 'user_id', 'usage_date'],
            'uniq_tenant_user_usage_date'
        );
        $this->ensureTenantUniqueKey(
            'class_waitlists',
            ['tenant_id', 'schedule_id', 'user_id'],
            'uniq_tenant_waitlist_schedule_user'
        );
        $this->ensureTenantUniqueKey(
            'class_notification_logs',
            ['tenant_id', 'attendance_id', 'notice_type'],
            'uniq_tenant_attendance_notice'
        );
        $this->ensureTenantUniqueKey(
            'class_followup_logs',
            ['tenant_id', 'attendance_id'],
            'uniq_tenant_attendance_followup'
        );
    }

    public function diagnostics(): array {
        $rows = [];
        $defaultTenantId = $this->defaultTenantId();

        foreach ($this->tenantTables as $table => $afterColumn) {
            $row = [
                'table' => $table,
                'exists' => false,
                'has_tenant_id' => false,
                'has_index' => false,
                'unassigned_count' => null,
                'unassigned' => 0,
                'ok' => false,
                'status' => 'missing',
                'message' => '',
                'default_tenant_id' => $defaultTenantId,
                'error' => $this->errors[$table] ?? '',
            ];

            try {
                $row['exists'] = $this->tableExists($table);
                if ($row['exists']) {
                    $row['has_tenant_id'] = $this->columnExists($table, 'tenant_id');
                    $row['has_index'] = $row['has_tenant_id'] && $this->indexExists($table, $this->indexName($table));
                    if ($row['has_tenant_id']) {
                        $stmt = $this->pdo->query(
                            'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE tenant_id IS NULL'
                        );
                        $row['unassigned_count'] = (int)$stmt->fetchColumn();
                        $row['unassigned'] = $row['unassigned_count'];
                    }
                }
            } catch (Throwable $e) {
                $row['error'] = $e->getMessage();
            }

            $row = $this->completeDiagnosticRow($row, $defaultTenantId);

            $rows[] = $row;
        }

        return $rows;
    }

    public function errors(): array {
        return $this->errors;
    }

    private function defaultTenantId(): int {
        $stmt = $this->pdo->query('SELECT id FROM tenants WHERE is_default = 1 ORDER BY id ASC LIMIT 1');
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        $tenant = $this->tenants->current();
        return $tenant && !empty($tenant['id']) ? (int)$tenant['id'] : 0;
    }

    private function completeDiagnosticRow(array $row, int $defaultTenantId): array {
        if (!empty($row['error'])) {
            $row['ok'] = false;
            $row['status'] = 'error';
            $row['message'] = '確認中にエラーが発生しました: ' . $row['error'];
            return $row;
        }

        if (empty($row['exists'])) {
            $row['ok'] = true;
            $row['status'] = 'not_used';
            $row['message'] = 'この環境では未使用のテーブルです。';
            return $row;
        }

        if (empty($row['has_tenant_id'])) {
            $row['ok'] = false;
            $row['status'] = 'missing_tenant_id';
            $row['message'] = 'tenant_id カラムがありません。アップデート後に自動追加される想定です。';
            return $row;
        }

        if (empty($row['has_index'])) {
            $row['ok'] = false;
            $row['status'] = 'missing_index';
            $row['message'] = 'tenant_id の索引がありません。件数が増える前に追加してください。';
            return $row;
        }

        if ((int)($row['unassigned_count'] ?? 0) > 0) {
            $row['ok'] = false;
            $row['status'] = 'unassigned';
            $row['message'] = 'tenant_id が未割当の行があります。標準テナントへ紐づけ直してください。';
            return $row;
        }

        $row['ok'] = true;
        $row['status'] = 'ok';
        $row['message'] = $defaultTenantId > 0
            ? '既存データは標準テナントに紐づいています。'
            : 'データ分離の準備は完了しています。';
        return $row;
    }

    private function tableExists(string $table): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function indexExists(string $table, string $indexName): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
        ");
        $stmt->execute([$table, $indexName]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function indexName(string $table): string {
        $name = 'idx_' . $table . '_tenant_id';
        if (strlen($name) <= 64) {
            return $name;
        }
        return 'idx_' . substr(md5($table), 0, 16) . '_tenant_id';
    }

    private function ensureTenantUniqueKey(string $table, array $columns, string $indexName): void {
        try {
            if (!$this->tableExists($table) || !$this->columnExists($table, 'tenant_id')) {
                return;
            }

            $stmt = $this->pdo->prepare("
                SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_csv
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND non_unique = 0
                  AND index_name <> 'PRIMARY'
                GROUP BY index_name
            ");
            $stmt->execute([$table]);
            $wanted = implode(',', $columns);
            $hasWanted = false;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index) {
                $csv = (string)($index['columns_csv'] ?? '');
                if ($csv === $wanted) {
                    $hasWanted = true;
                    continue;
                }

                $legacy = array_values(array_filter(explode(',', $csv), static fn($column) => $column !== 'tenant_id'));
                $wantedWithoutTenant = array_values(array_filter($columns, static fn($column) => $column !== 'tenant_id'));
                if ($legacy === $wantedWithoutTenant) {
                    $this->pdo->exec(
                        'ALTER TABLE ' . $this->quoteIdentifier($table) .
                        ' DROP INDEX ' . $this->quoteIdentifier((string)$index['index_name'])
                    );
                }
            }

            if (!$hasWanted) {
                $columnSql = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
                $this->pdo->exec(
                    'ALTER TABLE ' . $this->quoteIdentifier($table) .
                    ' ADD UNIQUE INDEX ' . $this->quoteIdentifier($indexName) . ' (' . $columnSql . ')'
                );
            }
        } catch (Throwable $e) {
            $this->errors[$table . ':unique'] = $e->getMessage();
        }
    }

    private function quoteIdentifier(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
