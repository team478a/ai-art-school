<?php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class RichMenuSegmentService {
    private PDO $pdo;
    private TenantScopeService $tenant;
    private string $token;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->token = trim($this->getSetting('line_channel_access_token', ''));
    }

    public function segments(): array {
        return ['first_time', 'attended', 'ticket', 'subscriber'];
    }

    public function segmentLabels(): array {
        return [
            'first_time' => '初回・未参加ユーザー',
            'attended' => '参加済みユーザー',
            'ticket' => '回数券ユーザー',
            'subscriber' => 'サブスク会員',
        ];
    }

    public function priorityLabels(): array {
        return [
            'subscriber' => 'サブスク会員を最優先',
            'ticket' => '回数券残数があるユーザー',
            'attended' => '参加履歴があるユーザー',
            'first_time' => '初回・未参加ユーザー',
        ];
    }

    public function buttonPresetOptions(): array {
        return [
            'generate' => '画像生成',
            'reserve' => '予約',
            'shop' => '購入・会員メニュー',
            'member' => '会員情報',
            'mypage' => 'マイページ',
            'gacha' => 'ガチャ',
            'help' => '使い方',
            'contact' => '問い合わせ',
            'none' => '未使用',
            'custom_message' => '自由設定（メッセージ）',
            'custom_url' => '自由設定（URL）',
        ];
    }

    public function buttonPresetDefinitions(): array {
        return [
            'generate' => ['icon' => '生成', 'label' => '画像生成', 'action' => 'url', 'text' => '', 'url' => '/liff/generate?from=richmenu', 'help' => '画像生成ページを開きます。'],
            'reserve' => ['icon' => '予約', 'label' => '予約', 'action' => 'url', 'text' => '', 'url' => '/liff/calendar?from=richmenu', 'help' => '予約カレンダーを開きます。'],
            'shop' => ['icon' => '購入', 'label' => '購入', 'action' => 'url', 'text' => '', 'url' => '/liff/shop?from=richmenu', 'help' => '回数券・サブスク購入ページを開きます。'],
            'member' => ['icon' => '会員', 'label' => '会員情報', 'action' => 'url', 'text' => '', 'url' => '/liff/shop?from=richmenu', 'help' => '会員・購入情報ページを開きます。'],
            'mypage' => ['icon' => 'MY', 'label' => 'マイページ', 'action' => 'message', 'text' => 'マイページ', 'url' => '', 'help' => 'マイページ案内メッセージを送ります。'],
            'gacha' => ['icon' => 'ガチャ', 'label' => 'ガチャ', 'action' => 'url', 'text' => '', 'url' => '/liff/gacha?from=richmenu', 'help' => 'ガチャページを開きます。'],
            'help' => ['icon' => '使い方', 'label' => '使い方', 'action' => 'message', 'text' => '使い方', 'url' => '', 'help' => '使い方案内メッセージを送ります。'],
            'contact' => ['icon' => '相談', 'label' => '問い合わせ', 'action' => 'message', 'text' => '問い合わせ', 'url' => '', 'help' => '問い合わせメッセージを送ります。'],
            'none' => ['icon' => '-', 'label' => '未使用', 'action' => 'message', 'text' => '未使用', 'url' => '', 'help' => '使わないボタンです。'],
            'custom_message' => ['icon' => '自由', 'label' => '自由設定', 'action' => 'message', 'text' => '', 'url' => '', 'help' => '送信テキストを手入力します。'],
            'custom_url' => ['icon' => 'URL', 'label' => '自由URL', 'action' => 'url', 'text' => '', 'url' => '', 'help' => 'URLを手入力します。'],
        ];
    }

    public function getConfig(): array {
        $config = [
            'segments_enabled' => $this->getSetting('rich_menu_segments_enabled', '0') === '1',
            'delivery_mode' => $this->getSetting('rich_menu_delivery_mode', 'segments'),
            'online_default_id' => $this->getSetting('rich_menu_online_default_id', ''),
            'generation_liff_id' => $this->generationLiffId(),
            'last_sync_result' => $this->getSetting('rich_menu_segments_last_sync_result', ''),
        ];

        foreach ($this->segments() as $segment) {
            $config[$segment . '_id'] = $this->getSetting('rich_menu_segment_' . $segment . '_id', '');
            for ($i = 1; $i <= 6; $i++) {
                $prefix = $segment . '_button_' . $i . '_';
                $settingPrefix = 'rich_menu_segment_' . $prefix;
                $defaultPreset = $i === 1 ? 'generate' : 'none';
                $config[$prefix . 'preset'] = $this->getSetting($settingPrefix . 'preset', $defaultPreset);
                $config[$prefix . 'icon'] = $this->getSetting($settingPrefix . 'icon', '');
                $config[$prefix . 'label'] = $this->getSetting($settingPrefix . 'label', '');
                $config[$prefix . 'action'] = $this->getSetting($settingPrefix . 'action', '');
                $config[$prefix . 'text'] = $this->getSetting($settingPrefix . 'text', '');
                $config[$prefix . 'url'] = $this->getSetting($settingPrefix . 'url', '');
            }
        }

        return $config;
    }

    public function saveConfig(array $post): void {
        $mode = (string)($post['rich_menu_delivery_mode'] ?? 'segments');
        $this->setSetting('rich_menu_delivery_mode', in_array($mode, ['default', 'segments'], true) ? $mode : 'segments');
        $this->setSetting('rich_menu_segments_enabled', !empty($post['rich_menu_segments_enabled']) ? '1' : '0');

        foreach ($this->segments() as $segment) {
            $idKey = 'rich_menu_segment_' . $segment . '_id';
            $this->setSetting($idKey, trim((string)($post[$idKey] ?? '')));
            for ($i = 1; $i <= 6; $i++) {
                $base = 'rich_menu_segment_' . $segment . '_button_' . $i . '_';
                $this->setSetting($base . 'preset', (string)($post[$base . 'preset'] ?? 'none'));
                $this->setSetting($base . 'icon', trim((string)($post[$base . 'icon'] ?? '')));
                $this->setSetting($base . 'label', trim((string)($post[$base . 'label'] ?? '')));
                $this->setSetting($base . 'action', (string)($post[$base . 'action'] ?? 'message'));
                $this->setSetting($base . 'text', trim((string)($post[$base . 'text'] ?? '')));
                $this->setSetting($base . 'url', trim((string)($post[$base . 'url'] ?? '')));
            }
        }
    }

    public function applyOnlineGenerationOnlyTemplate(): void {
        $this->setSetting('rich_menu_delivery_mode', 'default');
        $this->setSetting('rich_menu_segments_enabled', '0');
        $this->setSetting('generation_online_enabled', '1');
        $this->setSetting('class_mode_enabled', '0');

        $start = trim($this->getSetting('generation_window_start', ''));
        $end = trim($this->getSetting('generation_window_end', ''));
        $this->setSetting('generation_access_mode', ($start !== '' && $end !== '') ? 'time_window_only' : 'always_open');

        foreach ($this->segments() as $segment) {
            $this->applyPresetToButton($segment, 1, 'generate');
            for ($i = 2; $i <= 6; $i++) {
                $this->applyPresetToButton($segment, $i, 'none');
            }
        }
    }

    public function createOnlineDefaultRichMenu(): string {
        $payload = [
            'size' => ['width' => 2500, 'height' => 843],
            'selected' => true,
            'name' => '画像生成メニュー',
            'chatBarText' => '画像生成',
            'areas' => [[
                'bounds' => ['x' => 0, 'y' => 0, 'width' => 2500, 'height' => 843],
                'action' => [
                    'type' => 'uri',
                    'label' => '画像生成',
                    'uri' => $this->generationLiffUrl(),
                ],
            ]],
        ];

        $richMenuId = $this->createRichMenu($payload);
        $image = $this->makeOnlineImage();
        $this->uploadRichMenuImage($richMenuId, $image);
        @unlink($image);
        $this->apiRequest('POST', '/v2/bot/user/all/richmenu/' . rawurlencode($richMenuId));
        $unlinkResult = $this->unlinkTenantUsersFromIndividualMenus();
        $this->setSetting('rich_menu_online_default_id', $richMenuId);
        $this->setSetting('rich_menu_default_unlink_result', json_encode($unlinkResult, JSON_UNESCAPED_UNICODE));
        return $richMenuId;
    }

    public function createSegmentRichMenu(string $segment): string {
        if (!in_array($segment, $this->segments(), true)) {
            throw new RuntimeException('不正なセグメントです。');
        }
        $richMenuId = $this->createRichMenu($this->buildRichMenuPayload($segment));
        $image = $this->makeRichMenuImage($this->segmentLabels()[$segment] ?? 'リッチメニュー', $segment);
        $this->uploadRichMenuImage($richMenuId, $image);
        @unlink($image);
        $this->setSetting('rich_menu_segment_' . $segment . '_id', $richMenuId);
        return $richMenuId;
    }

    public function syncAll(int $limit = 500): array {
        if ($this->getSetting('rich_menu_delivery_mode', 'segments') === 'default') {
            return ['success' => 0, 'failed' => 0, 'message' => '共通メニュー運用のため、個別同期は不要です。'];
        }

        $rows = $this->fetchUsers($limit);
        $ok = 0;
        $ng = 0;
        foreach ($rows as $row) {
            $lineUserId = trim((string)($row['line_user_id'] ?? ''));
            if ($lineUserId === '') {
                continue;
            }
            if ($this->syncByLineUserId($lineUserId)) {
                $ok++;
            } else {
                $ng++;
            }
        }

        $result = ['success' => $ok, 'failed' => $ng];
        $this->setSetting('rich_menu_segments_last_sync_result', json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->setSetting('rich_menu_segments_last_sync_at', date('Y-m-d H:i:s'));
        return $result;
    }

    public function syncByLineUserId(string $lineUserId): bool {
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '' || $this->getSetting('rich_menu_delivery_mode', 'segments') === 'default') {
            return false;
        }
        $user = $this->findUserByLineId($lineUserId);
        if (!$user) {
            return false;
        }
        $segment = $this->detectSegment($user);
        $richMenuId = trim($this->getSetting('rich_menu_segment_' . $segment . '_id', ''));
        if ($richMenuId === '') {
            return false;
        }
        try {
            $this->apiRequest('POST', '/v2/bot/user/' . rawurlencode($lineUserId) . '/richmenu/' . rawurlencode($richMenuId));
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function applyPresetToButton(string $segment, int $number, string $preset): void {
        $def = $this->buttonPresetDefinitions()[$preset] ?? $this->buttonPresetDefinitions()['none'];
        $base = 'rich_menu_segment_' . $segment . '_button_' . $number . '_';
        $this->setSetting($base . 'preset', $preset);
        $this->setSetting($base . 'icon', (string)($def['icon'] ?? ''));
        $this->setSetting($base . 'label', (string)($def['label'] ?? ''));
        $this->setSetting($base . 'action', (string)($def['action'] ?? 'message'));
        $this->setSetting($base . 'text', (string)($def['text'] ?? ''));
        $this->setSetting($base . 'url', (string)($def['url'] ?? ''));
    }

    private function buildRichMenuPayload(string $segment): array {
        $areas = [];
        $cellWidth = 833;
        $cellHeight = 843;
        for ($i = 1; $i <= 6; $i++) {
            $button = $this->buttonConfig($segment, $i);
            if (($button['preset'] ?? '') === 'none') {
                continue;
            }
            $x = (($i - 1) % 3) * $cellWidth;
            $y = intdiv($i - 1, 3) * $cellHeight;
            $width = $i % 3 === 0 ? 2500 - $x : $cellWidth;
            $areas[] = [
                'bounds' => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $cellHeight],
                'action' => $this->buttonAction($button),
            ];
        }

        if (!$areas) {
            $areas[] = [
                'bounds' => ['x' => 0, 'y' => 0, 'width' => 2500, 'height' => 843],
                'action' => ['type' => 'uri', 'label' => '画像生成', 'uri' => $this->generationLiffUrl()],
            ];
        }

        return [
            'size' => ['width' => 2500, 'height' => 1686],
            'selected' => true,
            'name' => 'AIアート ' . ($this->segmentLabels()[$segment] ?? $segment),
            'chatBarText' => 'メニュー',
            'areas' => $areas,
        ];
    }

    private function buttonConfig(string $segment, int $number): array {
        $prefix = 'rich_menu_segment_' . $segment . '_button_' . $number . '_';
        $preset = $this->getSetting($prefix . 'preset', $number === 1 ? 'generate' : 'none');
        $def = $this->buttonPresetDefinitions()[$preset] ?? $this->buttonPresetDefinitions()['none'];
        return [
            'preset' => $preset,
            'icon' => $this->getSetting($prefix . 'icon', (string)($def['icon'] ?? '')),
            'label' => $this->getSetting($prefix . 'label', (string)($def['label'] ?? '')),
            'action' => $this->getSetting($prefix . 'action', (string)($def['action'] ?? 'message')),
            'text' => $this->getSetting($prefix . 'text', (string)($def['text'] ?? '')),
            'url' => $this->getSetting($prefix . 'url', (string)($def['url'] ?? '')),
        ];
    }

    private function buttonAction(array $button): array {
        $label = $this->lineLabel((string)($button['label'] ?? '開く'));
        if (($button['action'] ?? '') === 'url') {
            $url = trim((string)($button['url'] ?? ''));
            if ($url === '') {
                $url = '/liff/generate?from=richmenu';
            }
            return ['type' => 'uri', 'label' => $label, 'uri' => $this->menuActionUrl($url)];
        }
        $text = trim((string)($button['text'] ?? ''));
        if ($text === '') {
            $text = $label;
        }
        return ['type' => 'message', 'label' => $label, 'text' => $this->limitText($text, 300)];
    }

    private function createRichMenu(array $payload): string {
        $response = $this->apiRequest('POST', '/v2/bot/richmenu', $payload);
        $id = trim((string)($response['richMenuId'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('LINEからRich Menu IDが返りませんでした。');
        }
        return $id;
    }

    private function uploadRichMenuImage(string $richMenuId, string $imagePath): void {
        if (!is_file($imagePath)) {
            throw new RuntimeException('リッチメニュー画像を作成できませんでした。');
        }
        $this->apiRequest('POST', 'https://api-data.line.me/v2/bot/richmenu/' . rawurlencode($richMenuId) . '/content', null, file_get_contents($imagePath), 'image/png');
    }

    private function apiRequest(string $method, string $path, ?array $json = null, ?string $rawBody = null, string $contentType = 'application/json'): array {
        if ($this->token === '') {
            throw new RuntimeException('LINE Channel Access Token が未設定です。クライアント別設定でLINEトークンを設定してください。');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL拡張が利用できません。');
        }
        $url = preg_match('#^https?://#i', $path) ? $path : 'https://api.line.me' . $path;
        $headers = ['Authorization: Bearer ' . $this->token];
        if ($json !== null || $rawBody !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($rawBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException('LINE APIエラー: HTTP ' . $status . ' ' . ($body ?: $error));
        }
        if ($body === '' || $body === null) {
            return [];
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function makeOnlineImage(): string {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD拡張が利用できないため、リッチメニュー画像を作成できません。');
        }
        $img = imagecreatetruecolor(2500, 843);
        $purple = imagecolorallocate($img, 101, 78, 230);
        $purple2 = imagecolorallocate($img, 129, 105, 246);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 42, 35, 91);
        imagefilledrectangle($img, 0, 0, 2500, 843, $white);
        imagefilledrectangle($img, 0, 0, 2500, 84, $purple);
        imagefilledrectangle($img, 0, 755, 2500, 843, $purple2);
        imagefilledellipse($img, 430, 420, 430, 430, $purple);
        imagefilledpolygon($img, [375, 310, 375, 530, 555, 420], 3, $white);
        $this->drawScaledText($img, 'START', 1530, 365, 1450, 300, $ink);
        $this->drawScaledText($img, 'AI ART', 1530, 575, 820, 120, $purple);
        return $this->savePng($img);
    }

    private function makeRichMenuImage(string $title, string $segment): string {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD拡張が利用できないため、リッチメニュー画像を作成できません。');
        }
        $img = imagecreatetruecolor(2500, 1686);
        $bg = imagecolorallocate($img, 248, 249, 252);
        $border = imagecolorallocate($img, 225, 229, 236);
        $purple = imagecolorallocate($img, 112, 86, 245);
        $text = imagecolorallocate($img, 32, 38, 54);
        imagefilledrectangle($img, 0, 0, 2500, 1686, $bg);

        $cellWidth = 833;
        $cellHeight = 843;
        for ($i = 1; $i <= 6; $i++) {
            $button = $this->buttonConfig($segment, $i);
            $x = (($i - 1) % 3) * $cellWidth;
            $y = intdiv($i - 1, 3) * $cellHeight;
            $w = $i % 3 === 0 ? 2500 - $x : $cellWidth;
            imagefilledrectangle($img, $x + 12, $y + 12, $x + $w - 12, $y + $cellHeight - 12, imagecolorallocate($img, 255, 255, 255));
            imagerectangle($img, $x + 12, $y + 12, $x + $w - 12, $y + $cellHeight - 12, $border);
            $this->drawScaledText(
                $img,
                $this->asciiLabel((string)($button['label'] ?? 'MENU')),
                $x + (int)($w / 2),
                $y + 360,
                $w - 120,
                190,
                $text
            );
            if ($i === 1) {
                $this->drawScaledText(
                    $img,
                    $this->asciiLabel($title),
                    $x + (int)($w / 2),
                    $y + 535,
                    $w - 160,
                    110,
                    $purple
                );
            }
        }
        return $this->savePng($img);
    }

    private function drawScaledText($img, string $text, int $centerX, int $centerY, int $maxWidth, int $maxHeight, int $color): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $font = 5;
        $sourceWidth = max(1, imagefontwidth($font) * strlen($text));
        $sourceHeight = max(1, imagefontheight($font));
        $source = imagecreatetruecolor($sourceWidth, $sourceHeight);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        $transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);
        imagefilledrectangle($source, 0, 0, $sourceWidth, $sourceHeight, $transparent);

        $rgb = imagecolorsforindex($img, $color);
        $sourceColor = imagecolorallocatealpha(
            $source,
            (int)($rgb['red'] ?? 0),
            (int)($rgb['green'] ?? 0),
            (int)($rgb['blue'] ?? 0),
            0
        );
        imagestring($source, $font, 0, 0, $text, $sourceColor);

        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $targetWidth = max(1, (int)floor($sourceWidth * $scale));
        $targetHeight = max(1, (int)floor($sourceHeight * $scale));
        $targetX = (int)floor($centerX - ($targetWidth / 2));
        $targetY = (int)floor($centerY - ($targetHeight / 2));
        imagealphablending($img, true);
        imagecopyresized($img, $source, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($source);
    }

    private function savePng($img): string {
        $dir = BASE_PATH . '/storage/richmenus';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/richmenu_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($img, $file);
        imagedestroy($img);
        return $file;
    }

    private function fetchUsers(int $limit): array {
        $limit = max(1, min(5000, $limit));
        $where = "WHERE line_user_id IS NOT NULL AND line_user_id <> ''";
        $params = [];
        $tenantWhere = $this->tenant->andWhere('users');
        if ($tenantWhere !== '') {
            $where .= $tenantWhere;
            $params = array_merge($params, $this->tenant->params('users'));
        }
        $stmt = $this->pdo->prepare("SELECT * FROM users {$where} ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function unlinkTenantUsersFromIndividualMenus(int $limit = 5000): array {
        $success = 0;
        $failed = 0;
        foreach ($this->fetchUsers($limit) as $row) {
            $lineUserId = trim((string)($row['line_user_id'] ?? ''));
            if ($lineUserId === '') {
                continue;
            }
            try {
                $this->apiRequest('DELETE', '/v2/bot/user/' . rawurlencode($lineUserId) . '/richmenu');
                $success++;
            } catch (Throwable $e) {
                $failed++;
            }
        }
        return ['success' => $success, 'failed' => $failed];
    }

    private function findUserByLineId(string $lineUserId): ?array {
        $where = 'WHERE line_user_id = ?';
        $params = [$lineUserId];
        $tenantWhere = $this->tenant->andWhere('users');
        if ($tenantWhere !== '') {
            $where .= $tenantWhere;
            $params = array_merge($params, $this->tenant->params('users'));
        }
        $stmt = $this->pdo->prepare("SELECT * FROM users {$where} ORDER BY id DESC LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function detectSegment(array $user): string {
        $userId = (int)($user['id'] ?? 0);
        if ($this->hasActiveSubscription($userId)) {
            return 'subscriber';
        }
        if ($this->ticketBalance($userId) > 0) {
            return 'ticket';
        }
        if ($this->attendanceCount($userId) > 0) {
            return 'attended';
        }
        return 'first_time';
    }

    private function hasActiveSubscription(int $userId): bool {
        if ($userId <= 0 || !$this->tableExists('subscriptions')) {
            return false;
        }
        try {
            $where = 'user_id = ? AND status IN ("active","trialing")';
            $params = [$userId];
            $tenantWhere = $this->tenant->andWhere('subscriptions');
            if ($tenantWhere !== '') {
                $where .= $tenantWhere;
                $params = array_merge($params, $this->tenant->params('subscriptions'));
            }
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE {$where}");
            $stmt->execute($params);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function ticketBalance(int $userId): int {
        if ($userId <= 0) {
            return 0;
        }
        foreach (['ticket_balance', 'tickets'] as $column) {
            if ($this->columnExists('users', $column)) {
                $sql = 'SELECT `' . $column . '` FROM users WHERE id = ?' . $this->tenant->andWhere('users');
                $params = array_merge([$userId], $this->tenant->params('users'));
                return max(0, (int)$this->scalar($sql, $params));
            }
        }
        if ($this->tableExists('user_tickets')) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT COALESCE(SUM(remaining_count),0) FROM user_tickets WHERE user_id = ?'
                    . $this->tenant->andWhere('user_tickets')
                );
                $stmt->execute(array_merge([$userId], $this->tenant->params('user_tickets')));
                return max(0, (int)$stmt->fetchColumn());
            } catch (Throwable $e) {
                return 0;
            }
        }
        return 0;
    }

    private function attendanceCount(int $userId): int {
        if ($userId <= 0 || !$this->tableExists('class_attendances')) {
            return 0;
        }
        try {
            $where = 'user_id = ?';
            $params = [$userId];
            $tenantWhere = $this->tenant->andWhere('class_attendances');
            if ($tenantWhere !== '') {
                $where .= $tenantWhere;
                $params = array_merge($params, $this->tenant->params('class_attendances'));
            }
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM class_attendances WHERE {$where}");
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function generationLiffId(): string {
        foreach (['generate_liff_id', 'generation_liff_id', 'liff_generate_id'] as $key) {
            $value = trim($this->getSetting($key, ''));
            if ($value === '') {
                continue;
            }
            if (preg_match('#liff\.line\.me/([^/?#]+)#i', $value, $matches)) {
                return trim((string)$matches[1]);
            }
            return $value;
        }
        if ($this->isOnlineGenerationOnly()) {
            return $this->normalizeLiffId($this->getSetting('liff_id', ''));
        }
        return '';
    }

    private function isOnlineGenerationOnly(): bool {
        return $this->getSetting('service_operation_type', 'class_school') === 'online_generation'
            || (
                $this->getSetting('generation_online_enabled', '0') === '1'
                && $this->getSetting('class_mode_enabled', '1') !== '1'
            );
    }

    private function generationLiffUrl(): string {
        $liffId = $this->generationLiffId();
        if ($liffId === '') {
            throw new RuntimeException('画像生成用LIFF IDが未設定です。クライアント別設定の「画像生成用LIFF ID」を設定してください。');
        }
        return 'https://liff.line.me/' . rawurlencode($liffId);
    }

    private function menuActionUrl(string $url): string {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($path === '/liff/generate' || strpos($path, '/liff/generate/') === 0) {
            return $this->generationLiffUrl();
        }
        if ($path === '/liff/calendar' || $path === '/liff') {
            $liffId = trim($this->getSetting('liff_id', ''));
            if ($liffId !== '') {
                return 'https://liff.line.me/' . rawurlencode($this->normalizeLiffId($liffId));
            }
        }
        if ($path === '/liff/shop') {
            $liffId = trim($this->getSetting('shop_liff_id', ''));
            if ($liffId !== '') {
                return 'https://liff.line.me/' . rawurlencode($this->normalizeLiffId($liffId));
            }
        }
        return $this->absoluteUrl($url);
    }

    private function normalizeLiffId(string $value): string {
        if (preg_match('#liff\.line\.me/([^/?#]+)#i', $value, $matches)) {
            return trim((string)$matches[1]);
        }
        return trim($value);
    }

    private function absoluteUrl(string $path): string {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = trim($this->getSetting('public_base_url', ''));
        if ($base === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'school.sengoku-ai.com';
            $base = 'https://' . $host;
        }
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        $tenant = class_exists('Settings') ? Settings::currentTenant() : null;
        $tenantKey = trim((string)($tenant['tenant_key'] ?? ''));
        if ($tenantKey !== '' && empty($tenant['is_default'])) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $url .= $separator . 'tenant=' . rawurlencode($tenantKey);
        }
        return $url;
    }

    private function lineLabel(string $label): string {
        $label = trim($label);
        return $this->limitText($label !== '' ? $label : '開く', 20);
    }

    private function limitText(string $text, int $limit): string {
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') : $text;
        }
        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    }

    private function asciiLabel(string $text): string {
        $map = [
            '画像生成' => 'IMAGE',
            '予約' => 'RESERVE',
            '購入' => 'SHOP',
            '会員情報' => 'MEMBER',
            'マイページ' => 'MY PAGE',
            'ガチャ' => 'GACHA',
            '使い方' => 'HELP',
            '問い合わせ' => 'CONTACT',
            '初回・未参加ユーザー' => 'FIRST USER',
            '参加済みユーザー' => 'ATTENDED',
            '回数券ユーザー' => 'TICKET',
            'サブスク会員' => 'SUBSCRIBER',
        ];
        return $map[$text] ?? preg_replace('/[^A-Za-z0-9 _-]/', '', $text) ?: 'MENU';
    }

    private function getSetting(string $key, string $default = ''): string {
        return class_exists('Settings') ? Settings::get($key, $default) : $default;
    }

    private function setSetting(string $key, string $value): void {
        if (class_exists('Settings')) {
            Settings::set($key, $value);
        }
    }

    private function tableExists(string $table): bool {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function scalar(string $sql, array $params) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    }
}
