<?php

require_once BASE_PATH . '/app/Services/GenerationOpsService.php';

class GenerationRecoveryService
{
    private PDO $pdo;
    private GenerationOpsService $ops;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ops = new GenerationOpsService();
    }

    public function recoverTenant(int $tenantId, int $staleMinutes = 10): array
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('対象クライアントが正しくありません。');
        }

        $staleMinutes = max(2, min(1440, $staleMinutes));
        $cutoff = date('Y-m-d H:i:s', time() - ($staleMinutes * 60));
        $result = [
            'tenant_id' => $tenantId,
            'stale_minutes' => $staleMinutes,
            'requests_reset' => 0,
            'jobs_reset' => 0,
            'jobs_queued' => 0,
            'warnings' => [],
        ];

        foreach (['image_requests', 'job_queue'] as $table) {
            if ($this->tableExists($table) && !$this->columnExists($table, 'tenant_id')) {
                throw new RuntimeException($table . ' に tenant_id がないため、安全のため復旧を中止しました。');
            }
        }

        $this->pdo->beginTransaction();
        try {
            if ($this->tableExists('job_queue')) {
                $result['jobs_reset'] = $this->resetStaleJobs($tenantId, $cutoff, $result['warnings']);
            } else {
                $result['warnings'][] = 'job_queue テーブルはこの環境では使用されていません。';
            }

            if ($this->tableExists('image_requests')) {
                $result['requests_reset'] = $this->resetStaleRequests($tenantId, $cutoff, $result['warnings']);
                if ($this->tableExists('job_queue')) {
                    $result['jobs_queued'] = $this->queueMissingRequests($tenantId, $result['warnings']);
                }
            } else {
                $result['warnings'][] = 'image_requests テーブルがありません。';
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->ops->recordForTenant($tenantId, 'tenant_recovery_failed', null, ['error' => $e->getMessage()]);
            throw $e;
        }

        $this->ops->recordForTenant($tenantId, 'tenant_recovery_completed', null, $result);
        return $result;
    }

    private function resetStaleJobs(int $tenantId, string $cutoff, array &$warnings): int
    {
        if (!$this->columnExists('job_queue', 'status')) {
            $warnings[] = 'job_queue.status がないため、停止ジョブを復旧できませんでした。';
            return 0;
        }
        $timeColumn = $this->firstExistingColumn('job_queue', ['updated_at', 'created_at']);
        if ($timeColumn === '') {
            $warnings[] = 'job_queue に日時列がないため、停止判定を行いませんでした。';
            return 0;
        }

        $sets = ["status = 'pending'"];
        if ($this->columnExists('job_queue', 'available_at')) {
            $sets[] = 'available_at = NOW()';
        }
        foreach (['error_message', 'last_error'] as $column) {
            if ($this->columnExists('job_queue', $column)) {
                $sets[] = "`{$column}` = NULL";
            }
        }
        if ($this->columnExists('job_queue', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE job_queue SET ' . implode(', ', $sets)
            . " WHERE tenant_id = ? AND status = 'processing' AND `{$timeColumn}` < ?"
        );
        $stmt->execute([$tenantId, $cutoff]);
        return $stmt->rowCount();
    }

    private function resetStaleRequests(int $tenantId, string $cutoff, array &$warnings): int
    {
        if (!$this->columnExists('image_requests', 'status')) {
            $warnings[] = 'image_requests.status がないため、停止依頼を復旧できませんでした。';
            return 0;
        }
        $timeColumn = $this->firstExistingColumn('image_requests', ['updated_at', 'created_at']);
        if ($timeColumn === '') {
            $warnings[] = 'image_requests に日時列がないため、停止判定を行いませんでした。';
            return 0;
        }

        $sets = ["status = 'received'"];
        if ($this->columnExists('image_requests', 'error_message')) {
            $sets[] = 'error_message = NULL';
        }
        if ($this->columnExists('image_requests', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE image_requests SET ' . implode(', ', $sets)
            . " WHERE tenant_id = ? AND status IN ('analyzing','generating','uploading','sending')"
            . " AND `{$timeColumn}` < ?"
        );
        $stmt->execute([$tenantId, $cutoff]);
        return $stmt->rowCount();
    }

    private function queueMissingRequests(int $tenantId, array &$warnings): int
    {
        if (!$this->columnExists('job_queue', 'request_id')) {
            $warnings[] = 'job_queue.request_id がないため、依頼の再投入は行いませんでした。';
            return 0;
        }
        if (!$this->columnExists('image_requests', 'id') || !$this->columnExists('image_requests', 'status')) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.id FROM image_requests r
             LEFT JOIN job_queue q
               ON q.request_id = r.id
              AND q.tenant_id = r.tenant_id
              AND q.status IN ('pending','processing')
             WHERE r.tenant_id = ? AND r.status = 'received' AND q.request_id IS NULL
             ORDER BY r.id ASC LIMIT 100"
        );
        $stmt->execute([$tenantId]);
        $requestIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$requestIds) {
            return 0;
        }

        $columns = ['tenant_id', 'request_id', 'status'];
        $baseValues = [$tenantId, 0, 'pending'];
        $optional = [
            'job_type' => 'generate_images',
            'retry_count' => 0,
            'available_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        foreach ($optional as $column => $value) {
            if ($this->columnExists('job_queue', $column)) {
                $columns[] = $column;
                $baseValues[] = $value;
            }
        }

        $sql = 'INSERT INTO job_queue (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')';
        $insert = $this->pdo->prepare($sql);
        $count = 0;
        foreach ($requestIds as $requestId) {
            $values = $baseValues;
            $values[1] = $requestId;
            $insert->execute($values);
            $count++;
        }
        return $count;
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
