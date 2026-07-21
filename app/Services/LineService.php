<?php
require_once BASE_PATH . '/config/settings.php';

class LineService {
    private string $channelSecret;
    private string $accessToken;
    private string $apiBase = 'https://api.line.me/v2/bot';

    public function __construct() {
        $this->channelSecret = Settings::lineChannelSecret();
        $this->accessToken   = Settings::lineAccessToken();
    }

    public function verifySignature(string $body, string $signature): bool {
        $hash = base64_encode(hash_hmac('sha256', $body, $this->channelSecret, true));
        return hash_equals($hash, $signature);
    }

    public function replyText(string $replyToken, string $text): bool {
        return $this->apiPost('/message/reply', [
            'replyToken' => $replyToken,
            'messages'   => [['type' => 'text', 'text' => $text]],
        ]);
    }

    public function replyWithQuickReply(string $replyToken, string $text, array $quickReplyItems): bool {
        return $this->apiPost('/message/reply', [
            'replyToken' => $replyToken,
            'messages'   => [[
                'type' => 'text',
                'text' => $text,
                'quickReply' => ['items' => $quickReplyItems],
            ]],
        ]);
    }

    public function pushText(string $lineUserId, string $text): bool {
        return $this->apiPost('/message/push', [
            'to'       => $lineUserId,
            'messages' => [['type' => 'text', 'text' => $text]],
        ]);
    }

    public function pushWithQuickReply(string $lineUserId, string $text, array $quickReplyItems): bool {
        return $this->apiPost('/message/push', [
            'to'       => $lineUserId,
            'messages' => [[
                'type' => 'text',
                'text' => $text,
                'quickReply' => ['items' => $quickReplyItems],
            ]],
        ]);
    }

    public function pushImages(string $lineUserId, string $headerText, array $imageUrls): bool {
        $messages = [['type' => 'text', 'text' => $headerText]];
        foreach ($imageUrls as $url) {
            $url = $this->absoluteUrl((string)$url);
            if ($url === '') {
                continue;
            }
            $messages[] = [
                'type' => 'image',
                'originalContentUrl' => $url,
                'previewImageUrl' => $url,
            ];
        }

        if (count($messages) === 1) {
            return false;
        }

        foreach (array_chunk($messages, 5) as $chunk) {
            $ok = $this->apiPost('/message/push', [
                'to'       => $lineUserId,
                'messages' => $chunk,
            ]);
            if (!$ok) return false;
        }
        return true;
    }

    public function getProfile(string $lineUserId): ?array {
        $ch = curl_init("{$this->apiBase}/profile/{$lineUserId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->accessToken}"],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$res) return null;
        return json_decode($res, true);
    }

    public function getMessageContent(string $messageId): ?string {
        $ch = curl_init("https://api-data.line.me/v2/bot/message/{$messageId}/content");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->accessToken}"],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || $res === false || $res === '') return null;
        return $res;
    }

    private function apiPost(string $endpoint, array $data): bool {
        $ch = curl_init("{$this->apiBase}{$endpoint}");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->accessToken}",
            ],
            CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = ($code === 200);
        if ($ok && strpos($endpoint, '/message/push') === 0) {
            $messageCount = isset($data['messages']) && is_array($data['messages']) ? count($data['messages']) : 1;
            $this->incrementPushCount($messageCount);
        }
        return $ok;
    }

    private function incrementPushCount(int $n = 1): void {
        try {
            $key = 'line_push_count_' . date('Ym');
            $current = (int) Settings::get($key, '0');
            Settings::set($key, (string)($current + max(1, $n)));
        } catch (\Throwable $e) {
        }
    }

    private function absoluteUrl(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = '';
        if (class_exists('Settings')) {
            $base = trim((string)Settings::get('storage_public_url', ''));
            if ($base === '') {
                $base = trim((string)Settings::get('public_base_url', ''));
            }
            if ($base === '') {
                $base = trim((string)Settings::get('app_url', ''));
            }
            if ($base === '') {
                $base = trim((string)Settings::get('site_url', ''));
            }
            if ($base === '') {
                $base = trim((string)Settings::get('base_url', ''));
            }
        }
        if ($base === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'school.sengoku-ai.com';
            $base = 'https://' . $host;
        }

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}
