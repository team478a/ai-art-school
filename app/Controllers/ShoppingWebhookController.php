<?php

require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/ShoppingIntegrationService.php';

class ShoppingWebhookController
{
    public function diagnostic(): void
    {
        $service = new ShoppingIntegrationService();
        $tenant = Settings::currentTenant();

        $this->json([
            'ok' => true,
            'endpoint' => 'shopping_webhook',
            'method' => 'POST',
            'active' => $service->isActive(),
            'configured' => $service->isConfigured(),
            'tenant' => $tenant ? [
                'key' => (string)($tenant['tenant_key'] ?? ''),
                'name' => (string)($tenant['name'] ?? ''),
            ] : null,
        ]);
    }

    public function handle(): void
    {
        $rawBody = (string)file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? (array)getallheaders() : [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            }
        }

        try {
            $service = new ShoppingIntegrationService();
            if (!$service->isConfigured()) {
                $this->json(['ok' => false, 'error' => 'shopping_not_configured'], 503);
                return;
            }
            $result = $service->processWebhook($rawBody, $headers);
            $this->json(['ok' => true, 'result' => $result]);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 401);
        } catch (Throwable $e) {
            error_log('Shopping webhook error: ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'internal_error'], 500);
        }
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
