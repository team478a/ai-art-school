<?php

require_once BASE_PATH . '/config/settings.php';

class GenerationOpsService
{
    public function heartbeat(array $data = []): void
    {
        $this->heartbeatForTenant(Settings::tenantId(), $data);
    }

    public function heartbeatForTenant(?int $tenantId, array $data = []): void
    {
        $this->ensureDirectory();
        $payload = [
            'tenant_id' => $tenantId,
            'updated_at' => date('c'),
            'data' => $this->sanitize($data),
        ];
        @file_put_contents($this->heartbeatPath($tenantId), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function record(string $event, ?int $requestId = null, array $data = []): void
    {
        $this->recordForTenant(Settings::tenantId(), $event, $requestId, $data);
    }

    public function recordForTenant(?int $tenantId, string $event, ?int $requestId = null, array $data = []): void
    {
        $this->ensureDirectory();
        $payload = [
            'recorded_at' => date('c'),
            'tenant_id' => $tenantId,
            'event' => $event,
            'request_id' => $requestId,
            'data' => $this->sanitize($data),
        ];
        @file_put_contents($this->logPath($tenantId), json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        $this->heartbeatForTenant($tenantId, ['event' => $event, 'request_id' => $requestId]);
    }

    public function summary(?int $tenantId = null, int $limit = 300): array
    {
        $path = $this->logPath($tenantId);
        $summary = ['events' => [], 'last_event' => null, 'heartbeat' => null];
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice($lines, -max(1, $limit)) as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $event = (string)($row['event'] ?? 'unknown');
                $summary['events'][$event] = ($summary['events'][$event] ?? 0) + 1;
                $summary['last_event'] = $row;
            }
        }
        $heartbeatPath = $this->heartbeatPath($tenantId);
        if (is_file($heartbeatPath)) {
            $heartbeat = json_decode((string)file_get_contents($heartbeatPath), true);
            $summary['heartbeat'] = is_array($heartbeat) ? $heartbeat : null;
        }
        return $summary;
    }

    private function sanitize(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (preg_match('/(key|secret|token|authorization|password)/i', (string)$key)) {
                $safe[$key] = $value === '' || $value === null ? 'missing' : 'set';
                continue;
            }
            $safe[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }
        return $safe;
    }

    private function ensureDirectory(): void
    {
        $directory = BASE_PATH . '/storage/tenant_ops';
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
    }

    private function tenantKey(?int $tenantId = null): string
    {
        $id = $tenantId ?? Settings::tenantId();
        return $id === null ? 'default' : (string)$id;
    }

    private function logPath(?int $tenantId = null): string
    {
        return BASE_PATH . '/storage/tenant_ops/tenant_' . $this->tenantKey($tenantId) . '.jsonl';
    }

    private function heartbeatPath(?int $tenantId = null): string
    {
        return BASE_PATH . '/storage/tenant_ops/tenant_' . $this->tenantKey($tenantId) . '_heartbeat.json';
    }
}
