<?php

require_once BASE_PATH . '/app/Services/GenerationOpsService.php';

class TenantOperationsAuditService
{
    private PDO $pdo;
    private TenantService $tenants;
    private TenantDataService $tenantData;
    private TenantErrorMonitorService $errors;
    private TenantBackupService $backups;
    private GenerationOpsService $ops;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(
        PDO $pdo,
        TenantService $tenants,
        TenantDataService $tenantData,
        TenantErrorMonitorService $errors,
        TenantBackupService $backups
    ) {
        $this->pdo = $pdo;
        $this->tenants = $tenants;
        $this->tenantData = $tenantData;
        $this->errors = $errors;
        $this->backups = $backups;
        $this->ops = new GenerationOpsService();
    }

    public function audit(array $tenant, array $connectionDiagnostics = []): array
    {
        $tenantId = (int)($tenant['id'] ?? 0);
        $sections = [
            'connections' => $this->connectionSection($connectionDiagnostics),
            'generation' => $this->generationSection($tenantId),
            'errors' => $this->errorSection($tenantId),
            'isolation' => $this->isolationSection($tenant),
            'backup' => $this->backupSection($tenant),
            'permissions' => $this->permissionSection($tenant),
        ];

        $counts = ['ok' => 0, 'warning' => 0, 'ng' => 0];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $status = (string)($item['status'] ?? 'warning');
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        $total = array_sum($counts);
        return [
            'status' => $counts['ng'] > 0 ? 'ng' : ($counts['warning'] > 0 ? 'warning' : 'ok'),
            'score' => $total > 0 ? (int)round(($counts['ok'] / $total) * 100) : 100,
            'counts' => $counts,
            'sections' => $sections,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function connectionSection(array $diagnostics): array
    {
        $items = [];
        foreach (($diagnostics['groups'] ?? []) as $group) {
            foreach (($group['items'] ?? []) as $item) {
                $items[] = $this->item(
                    (string)($item['label'] ?? '接続設定'),
                    (string)($item['status'] ?? 'warning'),
                    (string)($item['value'] ?? $item['message'] ?? ''),
                    (string)($item['hint'] ?? '')
                );
            }
        }

        if (!$items) {
            $items[] = $this->item('外部サービス接続', 'warning', '接続診断の結果を取得できませんでした。');
        }
        return $this->section('外部サービス接続', $items);
    }

    private function generationSection(int $tenantId): array
    {
        $settings = $this->tenants->settings($tenantId);
        $staleMinutes = max(2, min(1440, (int)($settings['generation_stale_minutes'] ?? 10)));
        $requestStatuses = ['received', 'analyzing', 'generating', 'uploading', 'sending'];
        $jobStatuses = ['pending', 'processing'];

        $activeRequests = $this->statusCount('image_requests', $tenantId, $requestStatuses);
        $staleRequests = $this->staleCount('image_requests', $tenantId, $requestStatuses, $staleMinutes);
        $activeJobs = $this->statusCount('job_queue', $tenantId, $jobStatuses);
        $staleJobs = $this->staleCount('job_queue', $tenantId, $jobStatuses, $staleMinutes);

        $items = [
            $this->item(
                '画像生成依頼',
                $staleRequests > 0 ? 'ng' : 'ok',
                "処理中 {$activeRequests}件 / {$staleMinutes}分以上停止 {$staleRequests}件",
                $staleRequests > 0 ? '停止依頼は、この画面の「生成処理を復旧」で再投入できます。' : ''
            ),
            $this->item(
                'ジョブキュー',
                $staleJobs > 0 ? 'ng' : 'ok',
                "待機・処理中 {$activeJobs}件 / {$staleMinutes}分以上停止 {$staleJobs}件"
            ),
        ];

        $summary = $this->ops->summary($tenantId);
        $heartbeat = (string)($summary['heartbeat']['updated_at'] ?? '');
        $heartbeatTimestamp = $heartbeat !== '' ? (strtotime($heartbeat) ?: 0) : 0;
        $heartbeatLimit = max(900, $staleMinutes * 120);
        $heartbeatHealthy = $heartbeatTimestamp > 0 && time() - $heartbeatTimestamp <= $heartbeatLimit;
        $items[] = $this->item(
            '生成ワーカー',
            $heartbeatHealthy ? 'ok' : 'warning',
            $heartbeat !== '' ? '最終稼働: ' . $heartbeat : '稼働記録がまだありません。',
            $heartbeatHealthy ? '' : 'CRON設定またはワーカーの実行状況を確認してください。'
        );

        return $this->section('生成処理・CRON', $items);
    }

    private function errorSection(int $tenantId): array
    {
        $summary = $this->errors->summary($tenantId);
        $level = (string)($summary['level'] ?? 'ok');
        $status = $level === 'danger' ? 'ng' : ($level === 'warning' ? 'warning' : 'ok');
        $latest = (string)($summary['latest']['message'] ?? '直近のエラーはありません。');

        return $this->section('エラー監視', [
            $this->item('直近24時間', $status, (int)($summary['last24h'] ?? 0) . '件'),
            $this->item('直近7日間', $status, (int)($summary['last7d'] ?? 0) . '件'),
            $this->item('最新エラー', $status, $latest),
        ]);
    }

    private function isolationSection(array $tenant): array
    {
        $diagnostics = $this->tenantData->diagnostics();
        $items = [];
        foreach ($diagnostics as $row) {
            if (!is_array($row)) {
                continue;
            }
            $table = (string)($row['table'] ?? '不明なテーブル');
            $rowStatus = (string)($row['status'] ?? 'warning');
            if ($rowStatus === 'not_used') {
                $items[] = $this->item($table, 'ok', 'この環境では未使用です。');
                continue;
            }

            $hasTenant = !empty($row['has_tenant_id']);
            $hasIndex = !empty($row['has_index']);
            $unassigned = (int)($row['unassigned_count'] ?? $row['unassigned'] ?? 0);
            $status = !empty($row['ok']) ? 'ok' : ($hasTenant ? 'warning' : 'ng');
            $value = 'tenant_id: ' . ($hasTenant ? 'あり' : 'なし')
                . ' / 索引: ' . ($hasIndex ? 'あり' : 'なし')
                . ' / 未割当: ' . $unassigned . '件';
            $items[] = $this->item($table, $status, $value, (string)($row['message'] ?? ''));
        }

        if (!$items) {
            $items[] = $this->item('データ分離', 'warning', '対象テーブルを確認できませんでした。');
        }
        return $this->section('テナント分離', $items);
    }

    private function backupSection(array $tenant): array
    {
        $result = $this->backups->inspectLatest($tenant);
        return $this->section('バックアップ', [
            $this->item(
                (string)($result['label'] ?? 'バックアップ'),
                (string)($result['status'] ?? 'warning'),
                implode(' / ', array_map('strval', (array)($result['details'] ?? [])))
            ),
        ]);
    }

    private function permissionSection(array $tenant): array
    {
        $tenantId = (int)($tenant['id'] ?? 0);
        $isDefault = !empty($tenant['is_default']) || (string)($tenant['tenant_key'] ?? '') === 'default';

        if (!$this->tableExists('admin_users')
            || !$this->columnExists('admin_users', 'tenant_id')
            || !$this->columnExists('admin_users', 'role')) {
            return $this->section('権限監査', [
                $this->item('管理者権限', 'ng', 'admin_users の tenant_id または role 列がありません。'),
            ]);
        }

        $items = [];
        $hasStatus = $this->columnExists('admin_users', 'status');
        $scope = $isDefault
            ? '(tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)'
            : 'tenant_id = ?';
        $sql = $hasStatus
            ? "SELECT role, status, COUNT(*) AS cnt FROM admin_users WHERE {$scope} GROUP BY role, status"
            : "SELECT role, '' AS status, COUNT(*) AS cnt FROM admin_users WHERE {$scope} GROUP BY role";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $ownerCount = 0;
            foreach ($rows as $row) {
                $role = strtolower(trim((string)($row['role'] ?? '')));
                $count = (int)($row['cnt'] ?? 0);
                if (in_array($role, ['owner', 'system_admin'], true)) {
                    $ownerCount += $count;
                }
                $statusText = trim((string)($row['status'] ?? ''));
                $value = $count . '名' . ($statusText !== '' ? ' / 状態: ' . $statusText : '');
                $items[] = $this->item('権限: ' . ($role ?: '未設定'), $role === '' ? 'warning' : 'ok', $value);
            }
            $items[] = $this->item('オーナー権限', $ownerCount > 0 ? 'ok' : 'warning', $ownerCount . '名');
        } catch (Throwable $e) {
            $items[] = $this->item('管理者権限', 'ng', '権限情報を取得できませんでした。', $e->getMessage());
        }

        return $this->section('権限監査', $items);
    }

    private function statusCount(string $table, int $tenantId, array $statuses): int
    {
        if (!$statuses || !$this->tableExists($table)
            || !$this->columnExists($table, 'tenant_id')
            || !$this->columnExists($table, 'status')) {
            return 0;
        }
        try {
            $marks = implode(',', array_fill(0, count($statuses), '?'));
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = ? AND status IN ({$marks})");
            $stmt->execute(array_merge([$tenantId], $statuses));
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function staleCount(string $table, int $tenantId, array $statuses, int $minutes): int
    {
        if (!$statuses || !$this->tableExists($table)
            || !$this->columnExists($table, 'tenant_id')
            || !$this->columnExists($table, 'status')) {
            return 0;
        }
        $timeColumn = $this->columnExists($table, 'updated_at')
            ? 'updated_at'
            : ($this->columnExists($table, 'created_at') ? 'created_at' : '');
        if ($timeColumn === '') {
            return 0;
        }

        try {
            $marks = implode(',', array_fill(0, count($statuses), '?'));
            $cutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = ? AND status IN ({$marks}) AND `{$timeColumn}` < ?"
            );
            $stmt->execute(array_merge([$tenantId], $statuses, [$cutoff]));
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
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

    private function section(string $label, array $items): array
    {
        $status = 'ok';
        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'ng') {
                $status = 'ng';
                break;
            }
            if (($item['status'] ?? '') === 'warning') {
                $status = 'warning';
            }
        }
        return ['label' => $label, 'status' => $status, 'items' => $items];
    }

    private function item(string $label, string $status, string $value, string $hint = ''): array
    {
        $normalized = in_array($status, ['ok', 'warning', 'ng'], true) ? $status : 'warning';
        return [
            'label' => $label,
            'status' => $normalized,
            'value' => $value,
            'message' => $value,
            'hint' => $hint,
        ];
    }
}
