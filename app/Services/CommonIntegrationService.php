<?php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/IntegrationSchemaService.php';

class CommonIntegrationService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: get_pdo();
    }

    public static function registerSafely(int $localUserId, string $lineUserId, ?string $referralToken = null): void
    {
        try {
            $service = new self();
            if (!$service->isEnabled()) {
                return;
            }
            $service->registerLocalUser($localUserId, $lineUserId, $referralToken);
        } catch (Throwable $e) {
            error_log('Common integration registration skipped: ' . $e->getMessage());
        }
    }

    public function isEnabled(): bool
    {
        return (string) Settings::get('integration_enabled', '0') === '1';
    }

    public function registerLocalUser(int $localUserId, string $lineUserId, ?string $referralToken = null): void
    {
        if (!$this->isEnabled() || $localUserId <= 0 || $lineUserId === '') {
            return;
        }

        (new IntegrationSchemaService($this->pdo))->ensure();

        $tenantId = Settings::tenantId();
        $projectKey = trim((string) Settings::get('integration_project_key', 'ai-art-school'));
        $tenant = Settings::currentTenant();
        $tenantKey = trim((string) ($tenant['tenant_key'] ?? 'default'));
        $aiArtMemberId = 'aiart:' . $tenantKey . ':' . $projectKey . ':' . $localUserId;
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO integration_user_mappings
            (tenant_id, local_user_id, ai_art_member_id, line_user_id, project_key, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)
            ON DUPLICATE KEY UPDATE line_user_id = VALUES(line_user_id), project_key = VALUES(project_key),
                updated_at = VALUES(updated_at), status = IF(status = 'resolved', status, 'pending')";
        $this->pdo->prepare($sql)->execute([$tenantId, $localUserId, $aiArtMemberId, $lineUserId, $projectKey, $now, $now]);

        $commonPayload = [
            'tenant_key' => $tenantKey,
            'project_key' => $projectKey,
            'ai_art_member_id' => $aiArtMemberId,
            'line_user_id' => $lineUserId,
        ];
        $this->enqueue(
            'common-user-' . substr(hash('sha256', $aiArtMemberId), 0, 40),
            'common_user.resolve',
            'user',
            (string) $localUserId,
            $commonPayload
        );

        $referralToken = trim((string) $referralToken);
        if ($referralToken !== '') {
            $tokenHash = hash('sha256', $referralToken);
            $sql = "INSERT INTO integration_referral_mappings
                (tenant_id, local_user_id, referral_token_hash, status, created_at, updated_at)
                VALUES (?, ?, ?, 'pending', ?, ?)
                ON DUPLICATE KEY UPDATE referral_token_hash = VALUES(referral_token_hash),
                    status = IF(status = 'confirmed', status, 'pending'), updated_at = VALUES(updated_at)";
            $this->pdo->prepare($sql)->execute([$tenantId, $localUserId, $tokenHash, $now, $now]);

            $this->enqueue(
                'referral-' . substr(hash('sha256', $aiArtMemberId . ':' . $tokenHash), 0, 40),
                'referral.confirm',
                'user',
                (string) $localUserId,
                $commonPayload + ['referral_token' => $referralToken]
            );
        }
    }

    public function processNext(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $baseUrl = rtrim(trim((string) Settings::get('integration_common_id_base_url', '')), '/');
        $keyId = trim((string) Settings::get('integration_key_id', ''));
        $secret = trim((string) Settings::get('integration_hmac_secret', ''));
        if ($baseUrl === '' || $keyId === '' || $secret === '') {
            return false;
        }
        if (stripos($baseUrl, 'https://') !== 0 && stripos($baseUrl, 'http://localhost') !== 0) {
            throw new RuntimeException('Common ID API URL must use HTTPS.');
        }

        (new IntegrationSchemaService($this->pdo))->ensure();
        $event = $this->claimNextEvent();
        if (!$event) {
            return false;
        }

        try {
            $path = $event['event_type'] === 'referral.confirm'
                ? '/api/referrals/confirm'
                : '/api/common-users/resolve';
            $response = $this->postJson($baseUrl . $path, (string) $event['payload_json'], $keyId, $secret, (string) $event['event_id']);
            $this->completeEvent($event, $response);
        } catch (Throwable $e) {
            $this->retryEvent($event, $e->getMessage());
            error_log('Common integration delivery failed: event=' . $event['event_id'] . ' error=' . $e->getMessage());
        }

        return true;
    }

    private function enqueue(string $eventId, string $eventType, string $aggregateType, string $aggregateId, array $payload): void
    {
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO integration_outbox_events
            (tenant_id, event_id, event_type, aggregate_type, aggregate_id, payload_json, status, available_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
            ON DUPLICATE KEY UPDATE payload_json = IF(status = 'completed', payload_json, VALUES(payload_json)),
                status = IF(status = 'completed', status, 'pending'), available_at = IF(status = 'completed', available_at, VALUES(available_at)),
                updated_at = VALUES(updated_at)";
        $this->pdo->prepare($sql)->execute([
            Settings::tenantId(), $eventId, $eventType, $aggregateType, $aggregateId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now, $now,
        ]);
    }

    private function claimNextEvent(): ?array
    {
        $tenantId = Settings::tenantId();
        $scope = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $params = $tenantId === null ? [] : [$tenantId];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM integration_outbox_events
                WHERE {$scope} AND status IN ('pending', 'retry') AND available_at <= NOW()
                ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $stmt->execute($params);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare("UPDATE integration_outbox_events
                SET status = 'processing', attempts = attempts + 1, updated_at = NOW() WHERE id = ?")
                ->execute([(int) $event['id']]);
            $this->pdo->commit();
            $event['attempts'] = (int) $event['attempts'] + 1;
            return $event;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function postJson(string $url, string $body, string $keyId, string $secret, string $eventId): array
    {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $timeout = max(3, min(30, (int) Settings::get('integration_timeout_seconds', '10')));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Sengoku-Key-Id: ' . $keyId,
                'X-Sengoku-Timestamp: ' . $timestamp,
                'X-Sengoku-Signature: ' . $signature,
                'Idempotency-Key: ' . $eventId,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            throw new RuntimeException('Common API communication error: ' . $curlError);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Common API HTTP ' . $status);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Common API returned invalid JSON.');
        }
        return $decoded;
    }

    private function completeEvent(array $event, array $response): void
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
        $tenantId = Settings::tenantId();
        $scope = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $baseParams = $tenantId === null ? [] : [$tenantId];
        $localUserId = (int) $event['aggregate_id'];

        if ($event['event_type'] === 'common_user.resolve') {
            $commonUserId = trim((string) ($data['common_user_id'] ?? ''));
            if ($commonUserId === '') {
                throw new RuntimeException('Common API response has no common_user_id.');
            }
            $params = [$commonUserId, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            $params = array_merge($params, $baseParams, [$localUserId]);
            $this->pdo->prepare("UPDATE integration_user_mappings SET common_user_id = ?, status = 'resolved',
                response_json = ?, last_error = NULL, last_attempt_at = NOW(), resolved_at = NOW(), updated_at = NOW()
                WHERE {$scope} AND local_user_id = ?")->execute($params);
        } else {
            $params = [
                $data['registration_referrer_agent_code'] ?? null,
                $data['sales_agent_code'] ?? null,
                $data['assigned_agent_code'] ?? null,
            ];
            $params = array_merge($params, $baseParams, [$localUserId]);
            $this->pdo->prepare("UPDATE integration_referral_mappings SET
                registration_referrer_agent_code = ?, sales_agent_code = ?, assigned_agent_code = ?,
                status = 'confirmed', confirmed_at = NOW(), last_error = NULL, updated_at = NOW()
                WHERE {$scope} AND local_user_id = ?")->execute($params);
        }

        $this->pdo->prepare("UPDATE integration_outbox_events SET status = 'completed', payload_json = '{}',
            last_error = NULL, updated_at = NOW() WHERE id = ?")->execute([(int) $event['id']]);
    }

    private function retryEvent(array $event, string $error): void
    {
        $attempts = (int) $event['attempts'];
        $status = $attempts >= 8 ? 'failed' : 'retry';
        $delay = min(3600, max(60, (int) pow(2, min($attempts, 6)) * 60));
        $availableAt = date('Y-m-d H:i:s', time() + $delay);
        $this->pdo->prepare("UPDATE integration_outbox_events SET status = ?, available_at = ?,
            last_error = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$status, $availableAt, substr($error, 0, 2000), (int) $event['id']]);

        $tenantId = Settings::tenantId();
        $scope = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = ?';
        $params = [$error];
        if ($tenantId !== null) {
            $params[] = $tenantId;
        }
        $params[] = (int) $event['aggregate_id'];
        $table = $event['event_type'] === 'referral.confirm'
            ? 'integration_referral_mappings'
            : 'integration_user_mappings';
        $this->pdo->prepare("UPDATE {$table} SET status = ?, last_error = ?, updated_at = NOW()
            WHERE {$scope} AND local_user_id = ?")
            ->execute(array_merge([$status], $params));
    }
}
