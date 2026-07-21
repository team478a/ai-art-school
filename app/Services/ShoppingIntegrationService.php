<?php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/IntegrationSchemaService.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';
require_once BASE_PATH . '/app/Services/BillingService.php';

class ShoppingIntegrationService
{
    private PDO $pdo;
    private TenantScopeService $tenant;
    private BillingService $billing;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->billing = new BillingService();
        (new IntegrationSchemaService($this->pdo))->ensure();
    }

    public function isActive(): bool
    {
        return Settings::get('payment_provider', 'local_stripe') === 'shopping';
    }

    public function isConfigured(): bool
    {
        return Settings::get('shopping_checkout_base_url') !== ''
            && Settings::get('shopping_key_id') !== ''
            && Settings::get('shopping_hmac_secret') !== '';
    }

    public function createCheckoutUrl(int $userId, string $productKey, array $context = []): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('ショッピング決済の接続設定が完了していません。');
        }

        $productMap = json_decode(Settings::get('shopping_product_map_json', '{}'), true);
        if (!is_array($productMap) || !array_key_exists($productKey, $productMap)) {
            throw new RuntimeException('ショッピングの商品対応表に商品がありません: ' . $productKey);
        }

        $mapped = $productMap[$productKey];
        $productCode = is_array($mapped) ? (string)($mapped['product_code'] ?? '') : (string)$mapped;
        if ($productCode === '') {
            throw new RuntimeException('ショッピングの商品コードが空です: ' . $productKey);
        }

        $mapping = $this->findUserMapping($userId);
        if (!$mapping || empty($mapping['common_user_id'])) {
            throw new RuntimeException('共通ユーザーIDが未連携のため購入画面を開けません。');
        }

        $tenant = Settings::currentTenant() ?: [];
        $baseUrl = rtrim(Settings::get('public_base_url', ''), '/');
        if ($baseUrl === '') {
            $baseUrl = $this->requestBaseUrl();
        }

        $tenantKey = (string)($tenant['tenant_key'] ?? 'default');
        $shopReturnUrl = $baseUrl . '/liff/shop?tenant=' . rawurlencode($tenantKey);
        $payload = [
            'request_id' => $this->uuid(),
            'project_key' => 'ai_art_school',
            'tenant_key' => $tenantKey,
            'common_user_id' => (string)$mapping['common_user_id'],
            'ai_art_member_id' => (string)($mapping['ai_art_member_id'] ?? ''),
            'local_user_id' => $userId,
            'product_code' => $productCode,
            'product_key' => $productKey,
            'context' => $context,
            'return_url' => $shopReturnUrl . '&completed=1',
            'cancel_url' => $shopReturnUrl . '&cancelled=1',
        ];

        $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rawPayload === false) {
            throw new RuntimeException('購入情報を作成できませんでした。');
        }

        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $rawPayload, Settings::get('shopping_hmac_secret'));
        $query = http_build_query([
            'payload' => $this->base64UrlEncode($rawPayload),
            'timestamp' => $timestamp,
            'key_id' => Settings::get('shopping_key_id'),
            'signature' => 'v1=' . $signature,
        ], '', '&', PHP_QUERY_RFC3986);

        $checkoutUrl = Settings::get('shopping_checkout_base_url');
        return $checkoutUrl . (strpos($checkoutUrl, '?') === false ? '?' : '&') . $query;
    }

    public function processWebhook(string $rawBody, array $headers): array
    {
        $this->verifySignature($rawBody, $headers);

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new InvalidArgumentException('Webhook本文がJSONではありません。');
        }

        $eventId = (string)($event['event_id'] ?? '');
        $eventType = (string)($event['event_type'] ?? $event['type'] ?? '');
        if ($eventId === '' || $eventType === '') {
            throw new InvalidArgumentException('event_id または event_type がありません。');
        }

        $tenant = Settings::currentTenant() ?: [];
        $tenantId = !empty($tenant['id']) ? (int)$tenant['id'] : null;
        $tenantKey = (string)($tenant['tenant_key'] ?? 'default');
        $inboxId = $this->registerInbox($tenantId, $tenantKey, $eventId, $eventType, $rawBody);
        if ($inboxId === null) {
            return ['ok' => true, 'duplicate' => true, 'event_id' => $eventId];
        }

        try {
            $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : $event;
            if (strpos($eventType, 'order.') === 0 || strpos($eventType, 'payment.') === 0) {
                $this->projectPayment($tenantId, $tenantKey, $eventType, $data, $rawBody);
            } elseif (strpos($eventType, 'entitlement.') === 0) {
                $this->applyEntitlement($tenantId, $tenantKey, $eventType, $data, $rawBody);
            } else {
                throw new InvalidArgumentException('未対応のイベントです: ' . $eventType);
            }

            $this->markInbox($inboxId, 'processed');
            return ['ok' => true, 'duplicate' => false, 'event_id' => $eventId, 'event_type' => $eventType];
        } catch (Throwable $e) {
            $this->markInbox($inboxId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function verifySignature(string $rawBody, array $headers): void
    {
        $headers = $this->normalizeHeaders($headers);
        $timestamp = (string)($headers['x-sengoku-timestamp'] ?? '');
        $signature = (string)($headers['x-sengoku-signature'] ?? '');
        $keyId = (string)($headers['x-sengoku-key-id'] ?? '');

        if ($timestamp === '' || $signature === '' || $keyId === '') {
            throw new RuntimeException('Webhook署名ヘッダーが不足しています。');
        }
        if (!ctype_digit($timestamp)) {
            throw new RuntimeException('Webhook時刻が不正です。');
        }

        $tolerance = max(30, (int)Settings::get('shopping_webhook_tolerance_seconds', '300'));
        if (abs(time() - (int)$timestamp) > $tolerance) {
            throw new RuntimeException('Webhookの有効時間を過ぎています。');
        }
        if (!hash_equals(Settings::get('shopping_key_id'), $keyId)) {
            throw new RuntimeException('Webhook Key ID が一致しません。');
        }

        $expected = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, Settings::get('shopping_hmac_secret'));
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Webhook署名が一致しません。');
        }
    }

    private function registerInbox(?int $tenantId, string $tenantKey, string $eventId, string $eventType, string $rawBody): ?int
    {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO integration_inbox_events
                (tenant_id, tenant_key, event_id, event_type, source, payload_json, status, received_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'shopping', ?, 'received', NOW(), NOW(), NOW())");
            $stmt->execute([$tenantId, $tenantKey, $eventId, $eventType, $rawBody]);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    private function markInbox(int $id, string $status, string $error = ''): void
    {
        $stmt = $this->pdo->prepare("UPDATE integration_inbox_events
            SET status = ?, last_error = ?, processed_at = CASE WHEN ? = 'processed' THEN NOW() ELSE processed_at END, updated_at = NOW()
            WHERE id = ?");
        $stmt->execute([$status, $error !== '' ? $error : null, $status, $id]);
    }

    private function projectPayment(?int $tenantId, string $tenantKey, string $eventType, array $data, string $rawBody): void
    {
        $orderId = (string)($data['order_id'] ?? $data['payment_id'] ?? '');
        if ($orderId === '') {
            throw new InvalidArgumentException('決済イベントに order_id がありません。');
        }

        $statusMap = [
            'order.created' => 'created',
            'order.paid' => 'paid',
            'order.refunded' => 'refunded',
            'payment.succeeded' => 'paid',
            'payment.refunded' => 'refunded',
            'payment.failed' => 'failed',
        ];
        $status = (string)($data['status'] ?? $statusMap[$eventType] ?? 'updated');
        $paidAt = $status === 'paid' ? ($data['paid_at'] ?? date('Y-m-d H:i:s')) : null;
        $refundedAt = $status === 'refunded' ? ($data['refunded_at'] ?? date('Y-m-d H:i:s')) : null;

        $stmt = $this->pdo->prepare("INSERT INTO integration_payment_projections
            (tenant_id, tenant_key, order_id, common_user_id, status, amount, currency, payload_json, paid_at, refunded_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE common_user_id = VALUES(common_user_id), status = VALUES(status), amount = VALUES(amount),
                currency = VALUES(currency), payload_json = VALUES(payload_json),
                paid_at = COALESCE(VALUES(paid_at), paid_at), refunded_at = COALESCE(VALUES(refunded_at), refunded_at), updated_at = NOW()");
        $stmt->execute([
            $tenantId,
            $tenantKey,
            $orderId,
            $data['common_user_id'] ?? null,
            $status,
            isset($data['amount']) ? (int)$data['amount'] : null,
            $data['currency'] ?? null,
            $rawBody,
            $paidAt,
            $refundedAt,
        ]);
    }

    private function applyEntitlement(?int $tenantId, string $tenantKey, string $eventType, array $data, string $rawBody): void
    {
        $entitlementId = (string)($data['entitlement_id'] ?? '');
        $commonUserId = (string)($data['common_user_id'] ?? '');
        $type = strtolower((string)($data['entitlement_type'] ?? $data['type'] ?? ''));
        if ($entitlementId === '' || $commonUserId === '' || $type === '') {
            throw new InvalidArgumentException('受講権イベントの必須項目が不足しています。');
        }

        $localUserId = $this->resolveLocalUserId($commonUserId);
        if (!$localUserId) {
            throw new RuntimeException('共通ユーザーIDに対応する利用者が見つかりません。');
        }

        $previous = $this->findEntitlement($tenantKey, $entitlementId);
        $quantity = max(0, (int)($data['quantity'] ?? 0));
        $revoked = $eventType === 'entitlement.revoked';
        $status = $revoked ? 'revoked' : (string)($data['status'] ?? 'active');

        if (in_array($type, ['ticket', 'tickets', 'pass', 'passes'], true)) {
            $previousQuantity = $previous && ($previous['status'] ?? '') === 'active' ? (int)$previous['quantity'] : 0;
            $newQuantity = $revoked ? 0 : $quantity;
            $delta = $newQuantity - $previousQuantity;
            if ($delta !== 0) {
                $this->billing->addTickets($localUserId, $delta, 'shopping_entitlement', $entitlementId);
            }
        } elseif (in_array($type, ['subscription', 'membership'], true)) {
            if ($revoked) {
                if (!$this->hasOtherActiveSubscription($tenantKey, $localUserId, $entitlementId)) {
                    $this->billing->setMemberType($localUserId, 'none');
                }
            } else {
                $this->billing->setMemberType($localUserId, 'subscriber');
            }
        } elseif (in_array($type, ['attendance', 'class_attendance'], true)) {
            if (!$revoked && !empty($data['local_attendance_id'])) {
                $this->billing->markPaid((int)$data['local_attendance_id']);
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO integration_entitlement_projections
            (tenant_id, tenant_key, entitlement_id, local_user_id, common_user_id, entitlement_type, product_code,
             quantity, status, valid_from, valid_until, payload_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE local_user_id = VALUES(local_user_id), common_user_id = VALUES(common_user_id),
                entitlement_type = VALUES(entitlement_type), product_code = VALUES(product_code), quantity = VALUES(quantity),
                status = VALUES(status), valid_from = VALUES(valid_from), valid_until = VALUES(valid_until),
                payload_json = VALUES(payload_json), updated_at = NOW()");
        $stmt->execute([
            $tenantId,
            $tenantKey,
            $entitlementId,
            $localUserId,
            $commonUserId,
            $type,
            $data['product_code'] ?? null,
            $revoked ? 0 : $quantity,
            $status,
            $data['valid_from'] ?? null,
            $data['valid_until'] ?? null,
            $rawBody,
        ]);
    }

    private function findUserMapping(int $userId): ?array
    {
        $sql = 'SELECT * FROM integration_user_mappings WHERE local_user_id = ?' . $this->tenant->andWhere('integration_user_mappings') . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$userId], $this->tenant->params('integration_user_mappings')));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function resolveLocalUserId(string $commonUserId): ?int
    {
        $sql = 'SELECT local_user_id FROM integration_user_mappings WHERE common_user_id = ?' . $this->tenant->andWhere('integration_user_mappings') . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$commonUserId], $this->tenant->params('integration_user_mappings')));
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private function findEntitlement(string $tenantKey, string $entitlementId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM integration_entitlement_projections WHERE tenant_key = ? AND entitlement_id = ? LIMIT 1');
        $stmt->execute([$tenantKey, $entitlementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function hasOtherActiveSubscription(string $tenantKey, int $userId, string $excludeId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM integration_entitlement_projections
            WHERE tenant_key = ? AND local_user_id = ? AND entitlement_id <> ?
              AND entitlement_type IN ('subscription', 'membership') AND status = 'active'
              AND (valid_until IS NULL OR valid_until >= NOW())");
        $stmt->execute([$tenantKey, $userId, $excludeId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string)$name)] = is_array($value) ? implode(',', $value) : (string)$value;
        }
        return $normalized;
    }

    private function requestBaseUrl(): string
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        return ($https ? 'https://' : 'http://') . $host;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
