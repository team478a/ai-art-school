<?php
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/GachaService.php';

class GachaLiffController {
    private GachaService $service;

    public function __construct() {
        $this->service = new GachaService();
    }

    public function show(): void {
        $liffId = Settings::get('liff_id', '');
        $tenant = Settings::currentTenant();
        $tenantKey = trim((string)($tenant['tenant_key'] ?? ''));
        require BASE_PATH . '/app/Views/liff/gacha.php';
    }

    public function status(): void {
        $lineUserId = $this->lineUserIdFromRequest();
        $this->json($this->service->statusForLineUser($lineUserId));
    }

    public function draw(): void {
        $lineUserId = $this->lineUserIdFromRequest();
        $this->json($this->service->draw($lineUserId));
    }

    public function interest(): void {
        $lineUserId = $this->lineUserIdFromRequest();
        $message = trim((string)($_POST['message'] ?? ''));
        $this->json($this->service->markInterest($lineUserId, $message));
    }

    private function lineUserIdFromRequest(): string {
        $idToken = $_POST['idToken'] ?? '';
        if ($idToken === '') {
            $raw = file_get_contents('php://input');
            $json = json_decode($raw ?: '', true);
            if (is_array($json)) {
                $idToken = $json['idToken'] ?? '';
                $_POST = array_merge($_POST, $json);
            }
        }
        return $this->verifyIdToken((string)$idToken);
    }

    private function verifyIdToken(string $idToken): string {
        $liffChannelId = Settings::get('liff_channel_id', '');
        if ($liffChannelId === '') {
            $liffChannelId = Settings::get('line_login_channel_id', '');
        }
        if ($idToken === '' || $liffChannelId === '') {
            return '';
        }

        $ch = curl_init('https://api.line.me/oauth2/v2.1/verify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'id_token' => $idToken,
                'client_id' => $liffChannelId,
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$res) {
            return '';
        }
        $data = json_decode($res, true);
        return is_array($data) ? (string)($data['sub'] ?? '') : '';
    }

    private function json(array $data): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
