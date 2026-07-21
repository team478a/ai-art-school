<?php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';
require_once BASE_PATH . '/app/Services/GenerationTestAccessService.php';
require_once BASE_PATH . '/app/Services/CommonIntegrationService.php';

class LiffGenerateController {
    private PDO $pdo;
    private TenantScopeService $tenant;
    private GenerationTestAccessService $generationTestAccess;
    private array $columnCache = [];

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->generationTestAccess = new GenerationTestAccessService($this->pdo);
    }

    public function show(): void {
        $liffId = $this->firstSetting([
            'generate_liff_id',
            'generation_liff_id',
            'liff_generate_id',
        ]);
        if ($liffId === '' && $this->isOnlineGenerationOnly()) {
            $liffId = $this->setting('liff_id', '');
        }
        $currentTenant = Settings::currentTenant();
        $tenantKey = trim((string)($currentTenant['tenant_key'] ?? ''));
        $serviceName = $this->setting('service_name', $this->setting('classroom_name', 'AIアート画像生成'));
        require BASE_PATH . '/app/Views/liff/generate.php';
    }

    private function isOnlineGenerationOnly(): bool {
        return $this->setting('service_operation_type', 'class_school') === 'online_generation'
            || (
                $this->setting('generation_online_enabled', '0') === '1'
                && $this->setting('class_mode_enabled', '1') !== '1'
            );
    }

    public function request(): void {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->jsonError('送信内容を読み取れませんでした。もう一度お試しください。');
        }

        $inputText = trim((string)($payload['inputText'] ?? ''));
        if ($inputText === '' || $this->textLength($inputText) < 2) {
            $this->jsonError('作りたい画像の内容を入力してください。');
        }
        if ($this->textLength($inputText) > 500) {
            $this->jsonError('入力内容は500文字以内にしてください。');
        }

        $lineUserId = trim((string)($payload['lineUserId'] ?? ''));
        $displayName = trim((string)($payload['displayName'] ?? ''));
        $pictureUrl = trim((string)($payload['pictureUrl'] ?? ''));

        $verifiedLineUserId = $this->verifyIdToken(trim((string)($payload['idToken'] ?? '')));
        if ($verifiedLineUserId !== '') {
            $lineUserId = $verifiedLineUserId;
        }
        if ($lineUserId === '') {
            $this->jsonError('LINE認証に失敗しました。LINEアプリ内から開き直してください。');
        }

        $testMode = $this->generationTestAccess->isEnabledForLineUserId($lineUserId);
        if (!$testMode) {
            $canGenerate = $this->ensureCanGenerate($lineUserId);
            if (!$canGenerate['ok']) {
                $this->jsonError($canGenerate['message']);
            }

            $dailyLimit = max(1, (int)Settings::maxDailyPerUser());
            if ($this->countTodayRequests($lineUserId) >= $dailyLimit) {
                $this->jsonError('本日の生成上限に達しています。エラー分はカウントされません。');
            }
        }

        try {
            $userId = $this->upsertUser($lineUserId, $displayName, $pictureUrl);
            CommonIntegrationService::registerSafely(
                $userId,
                $lineUserId,
                trim((string)($payload['referralToken'] ?? $payload['referral_token'] ?? ''))
            );
            $requestId = $this->createImageRequest($userId, $lineUserId, $inputText);
            $this->createJob($requestId);
        } catch (Throwable $e) {
            $this->jsonError('生成依頼の登録に失敗しました。時間を置いてもう一度お試しください。');
        }

        $this->jsonAccepted([
            'ok' => true,
            'request_id' => $requestId,
            'message' => '生成依頼を受け付けました。完成した画像はこのLINEにお送りします。',
        ], $requestId);
    }

    private function ensureCanGenerate(string $lineUserId): array {
        if ($this->setting('generation_online_enabled', '1') !== '1') {
            return ['ok' => false, 'message' => '現在、オンライン生成は停止中です。'];
        }

        $today = date('Y-m-d');
        $startDate = trim($this->setting('generation_available_date_start', ''));
        $endDate = trim($this->setting('generation_available_date_end', ''));
        if ($startDate !== '' && $today < $startDate) {
            return ['ok' => false, 'message' => '生成受付はまだ開始していません。'];
        }
        if ($endDate !== '' && $today > $endDate) {
            return ['ok' => false, 'message' => '生成受付期間が終了しています。'];
        }

        $weekdays = $this->parseWeekdays($this->setting('generation_available_weekdays', ''));
        if ($weekdays && !in_array((int)date('w'), $weekdays, true)) {
            return ['ok' => false, 'message' => '本日は生成受付日ではありません。'];
        }

        $mode = $this->setting('generation_access_mode', '');
        if ($mode === '') {
            $mode = $this->setting('class_mode_enabled', '1') === '1' ? 'class_attendance' : 'always_open';
        }

        if (in_array($mode, ['always_open', 'online_only'], true)) {
            return ['ok' => true, 'message' => ''];
        }
        if (in_array($mode, ['time_window_only', 'online_time_window'], true)) {
            return $this->windowOpen();
        }
        if ($mode === 'class_or_time_window') {
            return $this->hasCheckedInToday($lineUserId) ? ['ok' => true, 'message' => ''] : $this->windowOpen();
        }
        if ($this->setting('class_mode_enabled', '1') !== '1') {
            return $this->windowOpen();
        }
        if ($this->hasCheckedInToday($lineUserId)) {
            return ['ok' => true, 'message' => ''];
        }

        return ['ok' => false, 'message' => '参加確認後に生成できます。予約を使わない場合は、管理画面でオンライン生成を有効にしてください。'];
    }

    private function windowOpen(): array {
        $start = substr(trim($this->setting('generation_window_start', '')), 0, 5);
        $end = substr(trim($this->setting('generation_window_end', '')), 0, 5);
        if ($start === '' || $end === '') {
            return ['ok' => true, 'message' => ''];
        }

        $now = (int)date('Hi');
        $s = (int)str_replace(':', '', $start);
        $e = (int)str_replace(':', '', $end);
        $open = $s <= $e ? ($now >= $s && $now <= $e) : ($now >= $s || $now <= $e);
        if ($open) {
            return ['ok' => true, 'message' => ''];
        }

        $custom = trim($this->setting('generation_window_message', ''));
        if ($custom !== '') {
            return ['ok' => false, 'message' => $custom];
        }
        return ['ok' => false, 'message' => '現在は生成受付時間外です。受付時間は ' . $start . ' - ' . $end . ' です。'];
    }

    private function parseWeekdays(string $value): array {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $map = [
            'sun' => 0, '日' => 0, '日曜' => 0, '日曜日' => 0,
            'mon' => 1, '月' => 1, '月曜' => 1, '月曜日' => 1,
            'tue' => 2, '火' => 2, '火曜' => 2, '火曜日' => 2,
            'wed' => 3, '水' => 3, '水曜' => 3, '水曜日' => 3,
            'thu' => 4, '木' => 4, '木曜' => 4, '木曜日' => 4,
            'fri' => 5, '金' => 5, '金曜' => 5, '金曜日' => 5,
            'sat' => 6, '土' => 6, '土曜' => 6, '土曜日' => 6,
        ];

        $days = [];
        foreach (preg_split('/[,\s、]+/u', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $key = strtolower($part);
            if (isset($map[$key])) {
                $days[] = $map[$key];
            } elseif (is_numeric($part)) {
                $days[] = max(0, min(6, (int)$part));
            }
        }
        return array_values(array_unique($days));
    }

    private function hasCheckedInToday(string $lineUserId): bool {
        if (!$this->tableExists('class_attendances') || !$this->tableExists('users')) {
            return false;
        }
        try {
            $sql = "SELECT COUNT(*)
                    FROM class_attendances ca
                    INNER JOIN users u ON u.id = ca.user_id
                    WHERE u.line_user_id = ?
                      AND ca.attended_at IS NOT NULL
                      AND DATE(ca.attended_at) = CURDATE()"
                    . $this->tenant->andWhere('class_attendances', 'ca')
                    . $this->tenant->andWhere('users', 'u');
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge(
                [$lineUserId],
                $this->tenant->params('class_attendances'),
                $this->tenant->params('users')
            ));
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function countTodayRequests(string $lineUserId): int {
        if (!$this->tableExists('image_requests')) {
            return 0;
        }

        $statusSql = $this->columnExists('image_requests', 'status')
            ? " AND status NOT IN ('failed','error','cancelled','canceled','deleted')"
            : '';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM image_requests
             WHERE line_user_id = ?
               AND DATE(created_at) = CURDATE()
               {$statusSql}" . $this->tenant->andWhere('image_requests')
        );
        $stmt->execute(array_merge([$lineUserId], $this->tenant->params('image_requests')));
        return (int)$stmt->fetchColumn();
    }

    private function upsertUser(string $lineUserId, string $displayName, string $pictureUrl): int {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE line_user_id = ?" . $this->tenant->andWhere('users') . " LIMIT 1");
        $stmt->execute(array_merge([$lineUserId], $this->tenant->params('users')));
        $id = (int)$stmt->fetchColumn();

        if ($id > 0) {
            $updates = [];
            $params = [];
            if ($this->columnExists('users', 'display_name')) {
                $updates[] = "display_name = COALESCE(NULLIF(?, ''), display_name)";
                $params[] = $displayName;
            }
            if ($this->columnExists('users', 'picture_url')) {
                $updates[] = "picture_url = COALESCE(NULLIF(?, ''), picture_url)";
                $params[] = $pictureUrl;
            }
            if ($this->columnExists('users', 'status')) {
                $updates[] = "status = 'active'";
            }
            if ($this->columnExists('users', 'updated_at')) {
                $updates[] = "updated_at = NOW()";
            }
            if ($updates) {
                $params[] = $id;
                $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?' . $this->tenant->andWhere('users');
                $this->pdo->prepare($sql)->execute(array_merge($params, $this->tenant->params('users')));
            }
            return $id;
        }

        $data = [
            'line_user_id' => $lineUserId,
            'display_name' => $displayName,
            'picture_url' => $pictureUrl,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        return $this->insertFiltered('users', $data);
    }

    private function createImageRequest(int $userId, string $lineUserId, string $inputText): int {
        $now = date('Y-m-d H:i:s');
        $data = [
            'user_id' => $userId,
            'line_user_id' => $lineUserId,
            'input_type' => 'free',
            'input_text' => $inputText,
            'status' => 'received',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return $this->insertFiltered('image_requests', $data);
    }

    private function createJob(int $requestId): void {
        if (!$this->tableExists('job_queue')) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $data = [
            'request_id' => $requestId,
            'job_type' => 'generate_images',
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($this->columnExists('job_queue', 'available_at')) {
            $data['available_at'] = $now;
        }
        $this->insertFiltered('job_queue', $data);
    }

    private function insertFiltered(string $table, array $data): int {
        $columns = [];
        $values = [];
        foreach ($data as $column => $value) {
            if ($this->columnExists($table, $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        }
        [$columns, $values] = $this->tenant->assignInsert($table, $columns, $values);
        if (empty($columns)) {
            throw new RuntimeException($table . ' に保存できる項目がありません。');
        }

        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    private function verifyIdToken(string $idToken): string {
        if ($idToken === '' || !function_exists('curl_init')) {
            return '';
        }
        $clientId = $this->firstSetting(['liff_channel_id', 'line_login_channel_id']);
        if ($clientId === '') {
            return '';
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
        if ($status < 200 || $status >= 300 || !is_string($body)) {
            return '';
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? (string)($decoded['sub'] ?? '') : '';
    }

    private function firstSetting(array $keys): string {
        foreach ($keys as $key) {
            $value = trim($this->setting($key, ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function setting(string $key, string $default = ''): string {
        try {
            return (string)Settings::get($key, $default);
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function tableExists(string $table): bool {
        try {
            $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
            ");
            $stmt->execute([$table, $column]);
            $this->columnCache[$cacheKey] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $this->columnCache[$cacheKey] = false;
        }
        return $this->columnCache[$cacheKey];
    }

    private function textLength(string $text): int {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function json(array $payload): void {
        $body = $this->encodeJson($payload);
        $this->beginJsonResponse($body, 200);
        echo $body;
        exit;
    }

    private function jsonAccepted(array $payload, int $requestId): void {
        $body = $this->encodeJson($payload);
        $this->beginJsonResponse($body, 202, [
            'X-AIArt-Request-Id: ' . $requestId,
        ]);
        echo $body;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }

        ignore_user_abort(true);
        @set_time_limit(0);
        try {
            require_once BASE_PATH . '/app/Workers/GenerateImagesWorker.php';
            (new GenerateImagesWorker())->run($requestId);
            Settings::set('worker_last_run', date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            error_log('LIFF generate worker failed request_id=' . $requestId . ': ' . $e->getMessage());
            try {
                $stmt = $this->pdo->prepare("
                    UPDATE image_requests
                    SET status = 'failed', error_message = ?, updated_at = NOW()
                    WHERE id = ?" . $this->tenant->andWhere('image_requests') . "
                ");
                $stmt->execute(array_merge(
                    [$e->getMessage(), $requestId],
                    $this->tenant->params('image_requests')
                ));
            } catch (Throwable $ignored) {
            }
        }
        exit;
    }

    private function encodeJson(array $payload): string {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return '{"ok":false,"message":"サーバー応答の作成に失敗しました。"}';
        }
        return $body;
    }

    private function beginJsonResponse(string $body, int $status, array $extraHeaders = []): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        foreach ($extraHeaders as $header) {
            header($header);
        }
    }

    private function jsonError(string $message): void {
        $this->json(['ok' => false, 'message' => $message]);
    }
}
