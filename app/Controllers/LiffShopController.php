<?php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';
require_once BASE_PATH . '/app/Services/CommonIntegrationService.php';
require_once BASE_PATH . '/app/Services/ShoppingIntegrationService.php';
require_once BASE_PATH . '/app/Services/StripeService.php';

class LiffShopController
{
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct()
    {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function show(): void
    {
        $shopping = new ShoppingIntegrationService($this->pdo);
        $tenant = Settings::currentTenant() ?: [];
        $view = [
            'serviceName' => (string)Settings::get('service_name', $tenant['service_name'] ?? 'AI Art'),
            'tenantKey' => (string)($tenant['tenant_key'] ?? ''),
            'liffId' => (string)Settings::get('shop_liff_id', Settings::get('liff_id', '')),
            'provider' => $shopping->isActive() ? 'shopping' : 'local_stripe',
            'products' => $this->products($shopping->isActive()),
            'completed' => isset($_GET['completed']),
            'cancelled' => isset($_GET['cancelled']),
        ];
        require BASE_PATH . '/app/Views/liff/shop.php';
    }

    public function checkout(): void
    {
        try {
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($input)) {
                $input = $_POST;
            }
            $idToken = trim((string)($input['idToken'] ?? ''));
            $productKey = trim((string)($input['productKey'] ?? ''));
            if ($idToken === '' || $productKey === '') {
                throw new InvalidArgumentException('LINE認証情報または商品情報が不足しています。');
            }

            $profile = $this->verifyIdToken($idToken);
            $lineUserId = (string)($profile['sub'] ?? '');
            if ($lineUserId === '') {
                throw new RuntimeException('LINEユーザーを確認できませんでした。');
            }

            $userId = $this->upsertUser(
                $lineUserId,
                (string)($input['displayName'] ?? $profile['name'] ?? ''),
                (string)($input['pictureUrl'] ?? $profile['picture'] ?? '')
            );
            CommonIntegrationService::registerSafely($userId, $lineUserId);

            $shopping = new ShoppingIntegrationService($this->pdo);
            if ($shopping->isActive()) {
                $url = $shopping->createCheckoutUrl($userId, $productKey, [
                    'line_user_id' => $lineUserId,
                    'source' => 'liff_shop',
                ]);
                $this->json(['ok' => true, 'url' => $url]);
                return;
            }

            $products = $this->products(false);
            if (!isset($products[$productKey])) {
                throw new InvalidArgumentException('選択した商品は現在購入できません。');
            }
            $url = $this->createLocalCheckout($products[$productKey], $userId, $lineUserId);
            if ($url === '') {
                throw new RuntimeException('決済ページを作成できませんでした。決済設定をご確認ください。');
            }
            $this->json(['ok' => true, 'url' => $url]);
        } catch (Throwable $e) {
            $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], $e instanceof InvalidArgumentException ? 400 : 422);
        }
    }

    private function products(bool $shopping): array
    {
        if ($shopping) {
            $map = json_decode((string)Settings::get('shopping_product_map_json', '{}'), true);
            $products = [];
            foreach (is_array($map) ? $map : [] as $key => $value) {
                $data = is_array($value) ? $value : ['product_code' => (string)$value];
                if (trim((string)($data['product_code'] ?? '')) === '') {
                    continue;
                }
                $products[(string)$key] = [
                    'key' => (string)$key,
                    'label' => (string)($data['label'] ?? $this->defaultLabel((string)$key)),
                    'description' => (string)($data['description'] ?? ''),
                    'price_label' => (string)($data['display_price'] ?? $data['price_label'] ?? ''),
                    'kind' => 'shopping',
                ];
            }
            return $products;
        }

        $products = [];
        $this->addPriceProduct($products, 'monthly', '月額会員', 'stripe_subscription_price_id', 'subscription_price_label', 'subscription');
        $this->addPriceProduct($products, 'annual', '年額会員', 'stripe_annual_subscription_price_id', 'annual_subscription_price_label', 'subscription');
        $this->addPriceProduct($products, 'one_time', '1回払い', 'one_time_price_id', 'one_time_price_label', 'price');
        $plans = json_decode((string)Settings::get('ticket_plans', '[]'), true);
        foreach (is_array($plans) ? $plans : [] as $index => $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $count = (int)($plan['count'] ?? 0);
            $amount = (int)($plan['amount'] ?? $plan['price'] ?? 0);
            if ($count < 1 || $amount < 1) {
                continue;
            }
            $key = 'ticket_' . $count . '_' . $index;
            $products[$key] = [
                'key' => $key,
                'label' => $count . '回券',
                'description' => '教室参加に利用できる前払い回数券です。',
                'price_label' => '¥' . number_format($amount),
                'kind' => 'ticket',
                'count' => $count,
                'amount' => $amount,
            ];
        }
        return $products;
    }

    private function addPriceProduct(array &$products, string $key, string $label, string $priceSetting, string $labelSetting, string $kind): void
    {
        $priceId = trim((string)Settings::get($priceSetting, ''));
        if ($priceId === '') {
            return;
        }
        $products[$key] = [
            'key' => $key,
            'label' => $label,
            'description' => $kind === 'subscription'
                ? '毎月または毎年自動更新される会員プランです。'
                : '1回払いの商品です。',
            'price_label' => (string)Settings::get($labelSetting, ''),
            'kind' => $kind,
            'price_id' => $priceId,
        ];
    }

    private function createLocalCheckout(array $product, int $userId, string $lineUserId): string
    {
        $stripe = new StripeService();
        if (!$stripe->isConfigured()) {
            return '';
        }
        $tenant = Settings::currentTenant() ?: [];
        $tenantKey = (string)($tenant['tenant_key'] ?? '');
        $base = $this->requestBaseUrl();
        $tenantQuery = $tenantKey !== '' ? '&tenant=' . rawurlencode($tenantKey) : '';
        $success = $base . '/liff/paid?status=success' . $tenantQuery;
        $cancel = $base . '/liff/shop?cancelled=1' . $tenantQuery;
        $metadata = ['kind' => $product['kind'], 'user_id' => $userId, 'line_user_id' => $lineUserId];

        if ($product['kind'] === 'subscription') {
            $session = $stripe->createSubscriptionCheckout((string)$product['price_id'], $metadata, $success, $cancel);
        } elseif ($product['kind'] === 'price') {
            $session = $stripe->createPriceCheckout((string)$product['price_id'], $metadata, $success, $cancel);
        } else {
            $metadata['ticket_count'] = (int)$product['count'];
            $session = $stripe->createCheckout((int)$product['amount'], (string)$product['label'], $metadata, $success, $cancel);
        }
        return (string)($session['url'] ?? '');
    }

    private function verifyIdToken(string $idToken): array
    {
        $clientId = trim((string)Settings::get('shop_liff_channel_id', Settings::get('liff_channel_id', Settings::get('line_login_channel_id', ''))));
        if ($clientId === '') {
            throw new RuntimeException('購入用LIFFのチャネルIDが設定されていません。');
        }
        $ch = curl_init('https://api.line.me/oauth2/v2.1/verify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['id_token' => $idToken, 'client_id' => $clientId]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$body, true);
        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException('LINE認証に失敗しました。購入用LIFF設定をご確認ください。');
        }
        return $data;
    }

    private function upsertUser(string $lineUserId, string $displayName, string $pictureUrl): int
    {
        $where = ['line_user_id = :line_user_id'];
        $params = [':line_user_id' => $lineUserId];
        if ($this->tenant->hasTenantColumn('users')) {
            $where[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = $this->tenant->tenantId();
        }
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $stmt->execute($params);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            $sets = ['display_name = :display_name'];
            $update = [':display_name' => $displayName, ':id' => $id];
            if ($this->hasColumn('users', 'picture_url')) {
                $sets[] = 'picture_url = :picture_url';
                $update[':picture_url'] = $pictureUrl;
            }
            $this->pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($update);
            return $id;
        }

        $columns = ['line_user_id', 'display_name'];
        $values = [$lineUserId, $displayName];
        if ($this->hasColumn('users', 'picture_url')) {
            $columns[] = 'picture_url';
            $values[] = $pictureUrl;
        }
        [$columns, $values] = $this->tenant->assignInsert('users', $columns, $values);
        $sql = 'INSERT INTO users (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $this->pdo->prepare($sql)->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            if (($row['Field'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    private function defaultLabel(string $key): string
    {
        return match ($key) {
            'monthly' => '月額会員',
            'annual' => '年額会員',
            'one_time' => '1回払い',
            default => str_starts_with($key, 'ticket_') ? str_replace('ticket_', '', $key) . '回券' : $key,
        };
    }

    private function requestBaseUrl(): string
    {
        $configured = rtrim((string)Settings::get('public_base_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
