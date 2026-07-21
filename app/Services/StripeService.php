<?php
// app/Services/StripeService.php
require_once BASE_PATH . '/config/settings.php';

class StripeService {
    private string $secretKey;
    private string $apiBase = 'https://api.stripe.com/v1';
    private string $lastError = '';

    public function __construct(?string $secretKey = null) {
        $this->secretKey = $secretKey ?? Settings::get('stripe_secret_key', '');
    }

    public function isConfigured(): bool {
        return trim($this->secretKey) !== '';
    }

    public function getLastError(): string {
        return $this->lastError;
    }

    public function createCheckout(int $amount, string $productName, array $metadata, string $successUrl, string $cancelUrl): ?array {
        $this->lastError = '';
        if (!$this->isConfigured()) {
            $this->lastError = 'Stripe secret key is not configured.';
            return null;
        }

        $params = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items[0][price_data][currency]' => 'jpy',
            'line_items[0][price_data][product_data][name]' => $productName,
            'line_items[0][price_data][unit_amount]' => $amount,
            'line_items[0][quantity]' => 1,
        ];
        foreach ($metadata as $k => $v) {
            $params["metadata[{$k}]"] = $v;
        }

        $res = $this->post('/checkout/sessions', $params);
        if (!$res || empty($res['url'])) {
            if ($this->lastError === '') {
                $this->lastError = 'Stripe checkout response did not include a URL.';
            }
            return null;
        }
        return ['id' => $res['id'] ?? '', 'url' => $res['url']];
    }

    public function createSubscriptionCheckout(string $priceId, array $metadata, string $successUrl, string $cancelUrl): ?array {
        $this->lastError = '';
        if (!$this->isConfigured()) {
            $this->lastError = 'Stripe secret key is not configured.';
            return null;
        }

        $params = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => 1,
        ];
        foreach ($metadata as $k => $v) {
            $params["metadata[{$k}]"] = $v;
            $params["subscription_data[metadata][{$k}]"] = $v;
        }

        $res = $this->post('/checkout/sessions', $params);
        if (!$res || empty($res['url'])) {
            if ($this->lastError === '') {
                $this->lastError = 'Stripe subscription checkout response did not include a URL.';
            }
            return null;
        }
        return ['id' => $res['id'] ?? '', 'url' => $res['url']];
    }

    public function createPriceCheckout(string $priceId, array $metadata, string $successUrl, string $cancelUrl): ?array {
        $this->lastError = '';
        if (!$this->isConfigured()) {
            $this->lastError = 'Stripe secret key is not configured.';
            return null;
        }
        if (trim($priceId) === '') {
            $this->lastError = 'Stripe Price ID is not configured.';
            return null;
        }

        $params = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => 1,
        ];
        foreach ($metadata as $k => $v) {
            $params["metadata[{$k}]"] = $v;
        }

        $res = $this->post('/checkout/sessions', $params);
        if (!$res || empty($res['url'])) {
            if ($this->lastError === '') {
                $this->lastError = 'Stripe checkout response did not include a URL.';
            }
            return null;
        }
        return ['id' => $res['id'] ?? '', 'url' => $res['url']];
    }

    public function cancelSubscription(string $subscriptionId): bool {
        if (!$this->isConfigured()) return false;
        $res = $this->request('DELETE', '/subscriptions/' . rawurlencode($subscriptionId), []);
        return is_array($res) && !empty($res['id']);
    }

    public function refundBySession(string $sessionId): bool {
        if (!$this->isConfigured() || !$sessionId) return false;
        $session = $this->get('/checkout/sessions/' . rawurlencode($sessionId));
        $paymentIntent = $session['payment_intent'] ?? '';
        if (!$paymentIntent) return false;
        $res = $this->post('/refunds', ['payment_intent' => $paymentIntent]);
        return is_array($res) && !empty($res['id']);
    }

    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'シークレットキーを入力してください。'];
        }
        $res = $this->get('/account');
        if ($res && !empty($res['id'])) {
            $name = $res['settings']['dashboard']['display_name'] ?? $res['id'];
            return ['ok' => true, 'message' => "Stripe接続成功: {$name}"];
        }
        return ['ok' => false, 'message' => $this->lastError ?: 'Stripe接続に失敗しました。'];
    }

    public function verifyWebhook(string $payload, string $sigHeader): ?array {
        $secret = Settings::get('stripe_webhook_secret', '');
        if (!$secret) {
            error_log('[StripeService] stripe_webhook_secret is not configured.');
            return null;
        }

        $parts = [];
        foreach (explode(',', $sigHeader) as $p) {
            $kv = explode('=', $p, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }
        $timestamp = $parts['t'] ?? '';
        $sig = $parts['v1'] ?? '';
        if (!$timestamp || !$sig) return null;

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        if (!hash_equals($expected, $sig)) return null;

        return json_decode($payload, true) ?: null;
    }

    private function post(string $path, array $params): ?array {
        return $this->request('POST', $path, $params);
    }

    private function get(string $path): ?array {
        return $this->request('GET', $path, []);
    }

    private function request(string $method, string $path, array $params): ?array {
        $this->lastError = '';
        $ch = curl_init($this->apiBase . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_TIMEOUT => 20,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($params);
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($params) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($params);
            }
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'Stripe cURL error: ' . ($curlError ?: 'unknown error');
            error_log('[StripeService] ' . $this->lastError);
            return null;
        }

        $data = json_decode((string)$body, true);
        if ($code >= 200 && $code < 300 && is_array($data)) {
            return $data;
        }

        $message = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? '') : '';
        $type = is_array($data) ? ($data['error']['type'] ?? '') : '';
        $param = is_array($data) ? ($data['error']['param'] ?? '') : '';

        $this->lastError = 'Stripe HTTP ' . $code;
        if ($type !== '') {
            $this->lastError .= ' ' . $type;
        }
        if ($param !== '') {
            $this->lastError .= ' param=' . $param;
        }
        if ($message !== '') {
            $this->lastError .= ': ' . $message;
        } elseif (is_string($body) && $body !== '') {
            $this->lastError .= ': ' . substr($body, 0, 300);
        }

        error_log('[StripeService] ' . $this->lastError);
        return null;
    }
}
