<?php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class AdminImageRequestController {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function dashboard(): void {
        $stats = [];
        $stats['today_requests'] = $this->countValue(
            "SELECT COUNT(*) FROM image_requests WHERE DATE(created_at) = CURDATE()" . $this->tenant->andWhere('image_requests'),
            $this->tenant->params('image_requests')
        );
        $stats['today_images'] = $this->countValue(
            "SELECT COUNT(*) FROM generated_images WHERE DATE(created_at) = CURDATE()" . $this->tenant->andWhere('generated_images'),
            $this->tenant->params('generated_images')
        );
        $stats['failed_count'] = $this->countValue(
            "SELECT COUNT(*) FROM image_requests WHERE status = 'failed' AND DATE(created_at) = CURDATE()" . $this->tenant->andWhere('image_requests'),
            $this->tenant->params('image_requests')
        );
        $stats['processing_count'] = $this->countValue(
            "SELECT COUNT(*) FROM image_requests WHERE status NOT IN ('completed','failed','canceled')" . $this->tenant->andWhere('image_requests'),
            $this->tenant->params('image_requests')
        );
        $stats['total_requests'] = $this->countValue(
            "SELECT COUNT(*) FROM image_requests WHERE 1=1" . $this->tenant->andWhere('image_requests'),
            $this->tenant->params('image_requests')
        );
        $stats['total_images'] = $this->countValue(
            "SELECT COUNT(*) FROM generated_images WHERE 1=1" . $this->tenant->andWhere('generated_images'),
            $this->tenant->params('generated_images')
        );

        $recentStmt = $this->pdo->prepare("
            SELECT r.*, u.display_name
            FROM image_requests r
            LEFT JOIN users u ON u.id = r.user_id" . $this->tenantJoinFilter('users', 'u') . "
            WHERE 1=1 " . $this->tenant->andWhere('image_requests', 'r') . "
            ORDER BY r.created_at DESC
            LIMIT 10
        ");
        $recentStmt->execute($this->tenant->params('image_requests'));
        $recent = $recentStmt->fetchAll();

        $monitor = [];
        $lastRun = class_exists('Settings') ? Settings::get('worker_last_run', '') : '';
        $monitor['worker_last_run'] = $lastRun;
        if ($lastRun) {
            $diff = time() - strtotime($lastRun);
            $monitor['worker_diff_sec'] = $diff;
            $monitor['worker_alert'] = $diff > 300;
        } else {
            $monitor['worker_diff_sec'] = null;
            $monitor['worker_alert'] = true;
        }

        $pushKey = 'line_push_count_' . date('Ym');
        $monitor['line_push_count'] = class_exists('Settings') ? (int) Settings::get($pushKey, '0') : 0;
        $monitor['line_push_limit'] = class_exists('Settings') ? (int) Settings::get('line_monthly_limit', '200') : 200;
        $monitor['line_push_alert'] = $monitor['line_push_limit'] > 0
            && $monitor['line_push_count'] >= $monitor['line_push_limit'] * 0.8;
        $monitor['stability_credits'] = class_exists('Settings') ? Settings::get('stability_credits_cache', '') : '';
        $monitor['stability_checked_at'] = class_exists('Settings') ? Settings::get('stability_credits_checked_at', '') : '';

        $settings = class_exists('Settings') ? Settings::all() : [];
        require BASE_PATH . '/app/Views/admin/dashboard.php';
    }

    public function index(): void {
        $where = [];
        $params = [];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        if (!empty($_GET['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['date'])) {
            $where[] = 'DATE(r.created_at) = ?';
            $params[] = $_GET['date'];
        }
        if (!empty($_GET['keyword'])) {
            $where[] = '(r.input_text LIKE ? OR u.display_name LIKE ?)';
            $params[] = '%' . $_GET['keyword'] . '%';
            $params[] = '%' . $_GET['keyword'] . '%';
        }
        $tenantWhere = $this->tenant->where('image_requests', 'r');
        if ($tenantWhere !== '') {
            $where[] = $tenantWhere;
            $params = array_merge($params, $this->tenant->params('image_requests'));
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $this->pdo->prepare(
            "SELECT COUNT(*) FROM image_requests r LEFT JOIN users u ON u.id = r.user_id"
            . $this->tenantJoinFilter('users', 'u') . " {$whereClause}"
        );
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmtList = $this->pdo->prepare("
            SELECT r.*, u.display_name,
                   (
                       SELECT COUNT(*)
                       FROM generated_images gi
                       WHERE gi.request_id = r.id
                         AND COALESCE(gi.image_url, '') <> ''
                         " . $this->tenant->andWhere('generated_images', 'gi') . "
                   ) AS image_count
            FROM image_requests r
            LEFT JOIN users u ON u.id = r.user_id" . $this->tenantJoinFilter('users', 'u') . "
            {$whereClause}
            ORDER BY r.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmtList->execute(array_merge(
            $this->tenant->params('generated_images'),
            $params
        ));
        $requests = $stmtList->fetchAll();

        $totalPages = (int)ceil($total / $perPage);
        $configuredPerPattern = max(1, min(4, (int)Settings::get('images_per_pattern', '4')));
        $expectedImageCount = min(
            max(1, min(8, Settings::maxImagesPerRequest())),
            $configuredPerPattern * 2
        );
        require BASE_PATH . '/app/Views/admin/image_requests.php';
    }

    public function show(int $id): void {
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.display_name, u.picture_url
            FROM image_requests r
            LEFT JOIN users u ON u.id = r.user_id" . $this->tenantJoinFilter('users', 'u') . "
            WHERE r.id = ?" . $this->tenant->andWhere('image_requests', 'r') . "
        ");
        $stmt->execute(array_merge([$id], $this->tenant->params('image_requests')));
        $request = $stmt->fetch();
        if (!$request) {
            http_response_code(404);
            echo '依頼が見つかりません。';
            return;
        }

        $stmtP = $this->pdo->prepare(
            "SELECT * FROM prompts WHERE request_id = ?"
            . $this->tenant->andWhere('prompts')
            . " ORDER BY prompt_type"
        );
        $stmtP->execute(array_merge([$id], $this->tenant->params('prompts')));
        $prompts = $stmtP->fetchAll();

        $stmtI = $this->pdo->prepare(
            "SELECT * FROM generated_images WHERE request_id = ?"
            . $this->tenant->andWhere('generated_images')
            . " ORDER BY prompt_type, image_no, id"
        );
        $stmtI->execute(array_merge([$id], $this->tenant->params('generated_images')));
        $images = $stmtI->fetchAll();

        $logs = [];
        if ($this->tableExists('system_logs')) {
            $stmtL = $this->pdo->prepare(
                "SELECT * FROM system_logs WHERE request_id = ?"
                . $this->tenant->andWhere('system_logs')
                . " ORDER BY created_at DESC LIMIT 50"
            );
            $stmtL->execute(array_merge([$id], $this->tenant->params('system_logs')));
            $logs = $stmtL->fetchAll();
        }

        $configuredEngine = trim((string)Settings::get('image_engine', 'stability')) ?: 'stability';
        $configuredPerPattern = max(1, min(4, (int)Settings::get('images_per_pattern', '4')));
        $effectiveImageCount = min(
            max(1, min(8, Settings::maxImagesPerRequest())),
            $configuredPerPattern * 2
        );
        $generationConfig = [
            'tenant' => Settings::tenantId() !== null ? (string)Settings::tenantId() : 'default',
            'engine' => $configuredEngine,
            'model' => $configuredEngine === 'openai'
                ? trim((string)Settings::get('openai_image_model', 'gpt-image-1'))
                : ($configuredEngine === 'grok'
                    ? trim((string)Settings::get('grok_image_model', 'grok-imagine-image'))
                    : trim((string)Settings::get('stability_model', 'sdxl'))),
            'quality' => trim((string)Settings::get('image_quality_level', 'standard')),
            'max_images' => $effectiveImageCount,
            'images_per_pattern' => $configuredPerPattern,
            'openai_key' => trim((string)Settings::get('openai_api_key', '')) !== '',
            'stability_key' => trim((string)Settings::get('stability_api_key', '')) !== '',
            'grok_key' => trim((string)Settings::get('grok_api_key', '')) !== '',
        ];

        require BASE_PATH . '/app/Views/admin/image_request_detail.php';
    }

    public function retry(int $id): void {
        $this->pdo->prepare("UPDATE image_requests SET status = 'received', error_message = NULL, updated_at = NOW() WHERE id = ?" . $this->tenant->andWhere('image_requests'))
            ->execute(array_merge([$id], $this->tenant->params('image_requests')));
        $this->pdo->prepare(
            "UPDATE job_queue
             SET status = 'pending', retry_count = 0, available_at = NOW(), updated_at = NOW()
             WHERE request_id = ? AND status = 'failed'"
            . $this->tenant->andWhere('job_queue')
        )->execute(array_merge([$id], $this->tenant->params('job_queue')));

        $stmtCheck = $this->pdo->prepare(
            "SELECT COUNT(*) FROM job_queue
             WHERE request_id = ? AND status IN ('pending','processing')"
            . $this->tenant->andWhere('job_queue')
        );
        $stmtCheck->execute(array_merge([$id], $this->tenant->params('job_queue')));
        if ((int)$stmtCheck->fetchColumn() === 0) {
            if ($this->tenant->hasTenantColumn('job_queue') && $this->tenant->tenantId()) {
                $this->pdo->prepare("INSERT INTO job_queue (tenant_id, request_id, job_type, status, created_at, updated_at) VALUES (?, ?, 'generate_images', 'pending', NOW(), NOW())")
                    ->execute([(int)$this->tenant->tenantId(), $id]);
            } else {
                $this->pdo->prepare("INSERT INTO job_queue (request_id, job_type, status, created_at, updated_at) VALUES (?, 'generate_images', 'pending', NOW(), NOW())")
                    ->execute([$id]);
            }
        }

        $this->writeLog($id, 'info', 'admin', '管理画面から再生成を受け付けました。');
        header('Location: /admin/image-requests/' . $id);
        exit;
    }

    public function processNow(int $id): void {
        $request = $this->findRequestForManualProcessing($id);
        if (!$request) {
            header('Location: /admin/image-requests?manual_error=not_found');
            exit;
        }
        if (in_array((string)($request['status'] ?? ''), ['completed', 'canceled'], true)) {
            header('Location: /admin/image-requests/' . $id . '?manual_error=invalid_status');
            exit;
        }

        try {
            $this->prepareManualQueue($id);
        } catch (Throwable $e) {
            error_log('Manual image queue preparation failed request_id=' . $id . ': ' . $e->getMessage());
            $this->writeLog($id, 'error', 'manual_worker', '手動処理の開始準備に失敗しました: ' . $e->getMessage());
            header('Location: /admin/image-requests/' . $id . '?manual_error=queue');
            exit;
        }

        $this->writeLog($id, 'info', 'manual_worker', '管理画面から手動処理を開始しました。');
        $this->finishResponseAndContinue('/admin/image-requests/' . $id . '?manual_queued=1');

        try {
            $this->runImageRequestManually($id, true);
        } catch (Throwable $e) {
            error_log('Manual image processing failed request_id=' . $id . ': ' . $e->getMessage());
            $this->writeLog($id, 'error', 'manual_worker', '手動処理に失敗しました: ' . $e->getMessage());
        }
        exit;
    }

    public function processNext(): void {
        $requestId = $this->findNextManualRequestId();

        if ($requestId !== null) {
            try {
                $this->prepareManualQueue($requestId);
            } catch (Throwable $e) {
                error_log('Manual cron queue preparation failed request_id=' . $requestId . ': ' . $e->getMessage());
                $this->writeLog($requestId, 'error', 'manual_worker', 'CRON代替処理の開始準備に失敗しました: ' . $e->getMessage());
                header('Location: /admin/dashboard?manual_error=queue');
                exit;
            }
        }

        $query = ['manual_queued' => '1'];
        if ($requestId !== null) {
            $query['request_id'] = (string)$requestId;
        } else {
            $query['manual_empty'] = '1';
        }
        $this->finishResponseAndContinue('/admin/dashboard?' . http_build_query($query));

        if ($requestId !== null) {
            try {
                $this->runImageRequestManually($requestId, true);
            } catch (Throwable $e) {
                error_log('Manual cron image processing failed request_id=' . $requestId . ': ' . $e->getMessage());
                $this->writeLog($requestId, 'error', 'manual_worker', 'CRON代替処理に失敗しました: ' . $e->getMessage());
            }
        }

        try {
            $this->runScheduledTasksManually();
        } catch (Throwable $e) {
            error_log('Manual cron scheduled tasks failed: ' . $e->getMessage());
        }

        exit;
    }

    public function resend(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }

        $stmt = $this->pdo->prepare("SELECT * FROM image_requests WHERE id = ?" . $this->tenant->andWhere('image_requests'));
        $stmt->execute(array_merge([$id], $this->tenant->params('image_requests')));
        $request = $stmt->fetch();
        if (!$request) {
            header('Location: /admin/image-requests?resend_error=not_found');
            exit;
        }

        $lineUserId = trim((string)($request['line_user_id'] ?? ''));
        if ($lineUserId === '') {
            header('Location: /admin/image-requests/' . $id . '?resend_error=no_line_user');
            exit;
        }

        $stmtImages = $this->pdo->prepare("
            SELECT gi.*, p.title_ja
            FROM generated_images gi
            LEFT JOIN prompts p ON p.id = gi.prompt_id" . $this->tenantJoinFilter('prompts', 'p') . "
            WHERE gi.request_id = ?
              AND COALESCE(gi.image_url, '') <> ''
              AND COALESCE(gi.status, 'generated') <> 'deleted'
              " . $this->tenant->andWhere('generated_images', 'gi') . "
            ORDER BY gi.prompt_type, gi.image_no, gi.id
        ");
        $stmtImages->execute(array_merge([$id], $this->tenant->params('generated_images')));
        $images = $stmtImages->fetchAll();
        if (!$images) {
            header('Location: /admin/image-requests/' . $id . '?resend_error=no_images');
            exit;
        }

        require_once BASE_PATH . '/app/Services/LineService.php';
        $line = new LineService();

        $groups = [];
        foreach ($images as $image) {
            $type = (string)($image['prompt_type'] ?? 'image');
            if (!isset($groups[$type])) {
                $groups[$type] = [
                    'title' => (string)($image['title_ja'] ?? ''),
                    'urls' => [],
                ];
            }
            $groups[$type]['urls'][] = $this->absoluteImageUrl((string)$image['image_url']);
        }

        $sentGroups = 0;
        $fallbackGroups = 0;
        foreach ($groups as $type => $group) {
            $urls = array_values(array_filter(array_unique($group['urls'])));
            if (!$urls) {
                continue;
            }
            $label = trim($group['title']) !== '' ? trim($group['title']) : ('作品 ' . $type);
            foreach (array_chunk($urls, 4) as $chunk) {
                $ok = $line->pushImages($lineUserId, "生成済み画像を再送します。\n{$label}", $chunk);
                if (!$ok) {
                    $textOk = $this->sendImageLinksFallback($line, $lineUserId, $label, $chunk);
                    if (!$textOk) {
                        $this->writeLog($id, 'error', 'line', '生成済み画像の再送に失敗しました。画像送信とURL送信の両方が失敗しました。');
                        header('Location: /admin/image-requests/' . $id . '?resend_error=failed');
                        exit;
                    }
                    $fallbackGroups++;
                    $this->writeLog($id, 'warning', 'line', '画像メッセージ送信に失敗したため、画像URLをテキストで再送しました。');
                    continue;
                }
                $sentGroups++;
            }
        }

        if ($sentGroups === 0 && $fallbackGroups === 0) {
            header('Location: /admin/image-requests/' . $id . '?resend_error=no_images');
            exit;
        }

        if ($fallbackGroups > 0) {
            $this->writeLog($id, 'info', 'line', '生成済み画像URLをLINEに再送しました。');
            header('Location: /admin/image-requests/' . $id . '?resent=link');
            exit;
        }

        $this->writeLog($id, 'info', 'line', '生成済み画像をLINE画像メッセージで再送しました。');
        header('Location: /admin/image-requests/' . $id . '?resent=image');
        exit;
    }

    private function sendImageLinksFallback(LineService $line, string $lineUserId, string $label, array $urls): bool {
        $urls = array_values(array_filter(array_map(fn($url) => $this->absoluteImageUrl((string)$url), $urls)));
        if (!$urls) {
            return false;
        }

        $lines = [
            "生成済み画像を再送します。",
            "LINEの画像送信が通らなかったため、画像URLでお送りします。",
            $label,
            '',
        ];
        foreach ($urls as $i => $url) {
            $lines[] = ((int)$i + 1) . '. ' . $url;
        }

        $text = implode("\n", $lines);
        if (strlen($text) <= 4500) {
            return $line->pushText($lineUserId, $text);
        }

        foreach (array_chunk($urls, 2) as $chunk) {
            $lines = [
                "生成済み画像を再送します。",
                "LINEの画像送信が通らなかったため、画像URLでお送りします。",
                $label,
                '',
            ];
            foreach ($chunk as $i => $url) {
                $lines[] = ((int)$i + 1) . '. ' . $url;
            }
            if (!$line->pushText($lineUserId, implode("\n", $lines))) {
                return false;
            }
        }
        return true;
    }

    private function absoluteImageUrl(string $url): string {
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

    private function findRequestForManualProcessing(int $id): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT id, status FROM image_requests WHERE id = ?"
            . $this->tenant->andWhere('image_requests')
            . " LIMIT 1"
        );
        $stmt->execute(array_merge([$id], $this->tenant->params('image_requests')));
        $request = $stmt->fetch();
        return $request ?: null;
    }

    private function findNextManualRequestId(): ?int {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT r.id
             FROM image_requests r
             LEFT JOIN job_queue q
               ON q.request_id = r.id"
            . $this->tenantJoinFilter('job_queue', 'q')
            . "
             WHERE r.status NOT IN ('completed', 'canceled')
               AND (
                    r.status = 'received'
                    OR q.status = 'pending'
                    OR (
                        q.status = 'processing'
                        AND q.updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                    )
               )"
            . $this->tenant->andWhere('image_requests', 'r')
            . " ORDER BY r.id ASC LIMIT 1"
        );
        $stmt->execute($this->tenant->params('image_requests'));
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private function runImageRequestManually(int $id, bool $queuePrepared = false): bool {
        if (!$queuePrepared) {
            $this->prepareManualQueue($id);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        require_once BASE_PATH . '/app/Workers/GenerateImagesWorker.php';
        $processed = (new GenerateImagesWorker())->run($id);
        if (class_exists('Settings')) {
            Settings::set('worker_last_run', date('Y-m-d H:i:s'));
        }
        $this->writeLog($id, 'info', 'manual_worker', '管理画面から画像生成の手動処理を実行しました。');
        return $processed;
    }

    private function finishResponseAndContinue(string $location): void {
        header('Location: ' . $location, true, 303);
        header('Content-Length: 0');
        header('Connection: close');

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    private function prepareManualQueue(int $id): void {
        $stmtActive = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM job_queue
             WHERE request_id = ?
               AND status = 'processing'
               AND updated_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
            . $this->tenant->andWhere('job_queue')
        );
        $stmtActive->execute(array_merge([$id], $this->tenant->params('job_queue')));
        if ((int)$stmtActive->fetchColumn() > 0) {
            throw new RuntimeException('この依頼は現在処理中です。');
        }

        $this->pdo->prepare(
            "UPDATE job_queue
             SET status = 'pending', available_at = NOW(), updated_at = NOW()
             WHERE request_id = ?
               AND (
                    status = 'failed'
                    OR status = 'pending'
                    OR (status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
               )"
            . $this->tenant->andWhere('job_queue')
        )->execute(array_merge([$id], $this->tenant->params('job_queue')));

        $stmtCheck = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM job_queue
             WHERE request_id = ? AND status IN ('pending','processing')"
            . $this->tenant->andWhere('job_queue')
        );
        $stmtCheck->execute(array_merge([$id], $this->tenant->params('job_queue')));
        if ((int)$stmtCheck->fetchColumn() > 0) {
            return;
        }

        if ($this->tenant->hasTenantColumn('job_queue') && $this->tenant->tenantId()) {
            $this->pdo->prepare(
                "INSERT INTO job_queue
                 (tenant_id, request_id, job_type, status, available_at, created_at, updated_at)
                 VALUES (?, ?, 'generate_images', 'pending', NOW(), NOW(), NOW())"
            )->execute([(int)$this->tenant->tenantId(), $id]);
            return;
        }

        $this->pdo->prepare(
            "INSERT INTO job_queue
             (request_id, job_type, status, available_at, created_at, updated_at)
             VALUES (?, 'generate_images', 'pending', NOW(), NOW(), NOW())"
        )->execute([$id]);
    }

    private function runScheduledTasksManually(): array {
        require_once BASE_PATH . '/app/Services/ReminderService.php';
        require_once BASE_PATH . '/app/Services/WaitlistNotificationService.php';
        require_once BASE_PATH . '/app/Services/ClassFollowupService.php';

        return [
            'reminded' => (int)(new ReminderService())->dispatchDue(),
            'waitlist' => (int)(new WaitlistNotificationService())->notifyOpenSlots(),
            'followups' => (int)(new ClassFollowupService())->dispatchDue(),
        ];
    }

    private function countValue(string $sql, array $params = []): int {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }

    private function tableExists(string $table): bool {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function writeLog(int $requestId, string $level, string $type, string $message): void {
        if (!$this->tableExists('system_logs')) {
            return;
        }
        try {
            [$columns, $values] = $this->tenant->assignInsert(
                'system_logs',
                ['log_level', 'log_type', 'message', 'request_id'],
                [$level, $type, $message, $requestId]
            );
            $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $stmt = $this->pdo->prepare(
                'INSERT INTO system_logs (' . implode(', ', $quotedColumns)
                . ', created_at) VALUES (' . $placeholders . ', NOW())'
            );
            $stmt->execute($values);
        } catch (Throwable $e) {
        }
    }
}
