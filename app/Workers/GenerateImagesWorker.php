<?php
require_once BASE_PATH . '/config/app.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/Logger.php';
require_once BASE_PATH . '/app/Services/PromptService.php';
require_once BASE_PATH . '/app/Services/ImageGenerationService.php';
require_once BASE_PATH . '/app/Services/StorageService.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';
require_once BASE_PATH . '/app/Services/ImageQualityService.php';
require_once BASE_PATH . '/app/Services/GenerationOpsService.php';

class GenerateImagesWorker {
    private PDO $pdo;
    private ?PromptService $promptSvc = null;
    private ?ImageGenerationService $imageSvc = null;
    private ?StorageService $storageSvc = null;
    private ?LineService $lineSvc = null;
    private ?TenantScopeService $tenant = null;
    private ?ImageQualityService $qualitySvc = null;
    private ?GenerationOpsService $opsSvc = null;

    public function __construct() {
        $this->pdo = get_pdo();
    }

    public function run(?int $preferredRequestId = null): bool {
        $previousTenantId = Settings::tenantId();
        $staleMinutes = $this->staleMinutes();
        $this->pdo->prepare("
            UPDATE job_queue
            SET status = 'pending', available_at = NOW(), updated_at = NOW()
            WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL {$staleMinutes} MINUTE)
        ")->execute();
        $this->recoverOrphanedRequests($preferredRequestId);

        $this->pdo->beginTransaction();
        try {
            $requestWhere = $preferredRequestId && $preferredRequestId > 0 ? ' AND request_id = ?' : '';
            $stmt = $this->pdo->prepare("
                SELECT * FROM job_queue
                WHERE status = 'pending'
                  AND (available_at IS NULL OR available_at <= NOW())
                  {$requestWhere}
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute($requestWhere !== '' ? [$preferredRequestId] : []);
            $job = $stmt->fetch();
            if (!$job) {
                $this->pdo->rollBack();
                return false;
            }
            $this->pdo->prepare("UPDATE job_queue SET status = 'processing', updated_at = NOW() WHERE id = ?")->execute([$job['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $requestId = (int)$job['request_id'];
        try {
            $this->activateTenantForJob($job, $requestId, $previousTenantId);
            $this->initializeTenantServices();
            $this->opsSvc->record('job_started', $requestId, ['job_id' => (int)$job['id']]);
            $this->process($requestId);
            $this->pdo->prepare("UPDATE job_queue SET status = 'completed', updated_at = NOW() WHERE id = ?")->execute([$job['id']]);
            $this->opsSvc->record('job_completed', $requestId, ['job_id' => (int)$job['id']]);
        } catch (\Throwable $e) {
            $this->handleFailure($job, $requestId, $e);
        } finally {
            Settings::useTenantId($previousTenantId);
        }
        return true;
    }

    private function activateTenantForJob(array $job, int $requestId, ?int $fallbackTenantId): void {
        $tenantId = isset($job['tenant_id']) ? (int)$job['tenant_id'] : 0;
        if ($tenantId <= 0 && $this->columnExists('image_requests', 'tenant_id')) {
            $stmt = $this->pdo->prepare('SELECT tenant_id FROM image_requests WHERE id = ? LIMIT 1');
            $stmt->execute([$requestId]);
            $tenantId = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($tenantId <= 0) {
            $tenantId = (int)($fallbackTenantId ?? 0);
        }
        Settings::useTenantId($tenantId > 0 ? $tenantId : null);
    }

    private function recoverOrphanedRequests(?int $preferredRequestId = null): void {
        $staleMinutes = $this->staleMinutes();
        $queueColumns = ['request_id', 'job_type', 'status'];
        $selectColumns = ['r.id', "'generate_images'", "'pending'"];

        if ($this->columnExists('job_queue', 'tenant_id')) {
            $queueColumns[] = 'tenant_id';
            $selectColumns[] = $this->columnExists('image_requests', 'tenant_id') ? 'r.tenant_id' : 'NULL';
        }
        if ($this->columnExists('job_queue', 'available_at')) {
            $queueColumns[] = 'available_at';
            $selectColumns[] = 'NOW()';
        }
        if ($this->columnExists('job_queue', 'created_at')) {
            $queueColumns[] = 'created_at';
            $selectColumns[] = 'NOW()';
        }
        if ($this->columnExists('job_queue', 'updated_at')) {
            $queueColumns[] = 'updated_at';
            $selectColumns[] = 'NOW()';
        }

        $requestFilter = $preferredRequestId && $preferredRequestId > 0 ? ' AND r.id = ?' : '';
        $quotedColumns = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $queueColumns));
        $sql = "
            INSERT INTO job_queue ({$quotedColumns})
            SELECT " . implode(', ', $selectColumns) . "
            FROM image_requests r
            WHERE (
                    r.status = 'received'
                    OR (
                        r.status IN ('analyzing', 'generating', 'uploading', 'sending')
                        AND r.updated_at < DATE_SUB(NOW(), INTERVAL {$staleMinutes} MINUTE)
                    )
                  )
              {$requestFilter}
              AND NOT EXISTS (
                  SELECT 1
                  FROM job_queue q
                  WHERE q.request_id = r.id
                    AND q.status IN ('pending', 'processing')
              )
            ORDER BY r.id ASC
            LIMIT 20
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($requestFilter !== '' ? [(int)$preferredRequestId] : []);
    }

    private function initializeTenantServices(): void {
        $this->tenant = new TenantScopeService($this->pdo);
        $this->promptSvc = new PromptService();
        $this->imageSvc = new ImageGenerationService();
        $this->storageSvc = new StorageService();
        $this->lineSvc = new LineService();
        $this->qualitySvc = new ImageQualityService();
        $this->opsSvc = new GenerationOpsService();
        $this->opsSvc->heartbeat(['worker' => 'GenerateImagesWorker', 'state' => 'ready']);
        $this->ensurePhotoColumns();
    }

    private function process(int $requestId): void {
        $req = $this->getRequest($requestId);
        if (!$req) {
            throw new \RuntimeException("request not found: {$requestId}");
        }

        if (in_array((string)($req['status'] ?? ''), ['analyzing', 'generating', 'uploading', 'sending', 'failed'], true)) {
            $this->clearIncompleteArtifacts($requestId);
        }

        if (($req['input_type'] ?? '') === 'photo_illustration') {
            $this->processPhotoIllustration($req);
            return;
        }

        $this->processTextGeneration($req);
    }

    private function processPhotoIllustration(array $req): void {
        $requestId = (int)$req['id'];
        $this->updateRequestStatus($requestId, 'generating');

        $sourcePath = (string)($req['source_image_path'] ?? '');
        $styleKey = (string)($req['photo_style'] ?? 'anime');
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new \RuntimeException('元写真が見つかりません。もう一度写真を送ってください。');
        }

        $label = $this->photoStyleLabel($styleKey);
        $promptId = $this->savePrompt($requestId, 'P', [
            'title_ja' => '写真イラスト化',
            'input_summary_ja' => 'LINEで送信された写真をイラスト化',
            'prompt_en' => 'Photo to illustration style: ' . $styleKey,
            'safety_notes' => '本人または利用許可を得た写真のみ利用してください。',
        ]);

        $images = $this->imageSvc->generateFromImage($sourcePath, $styleKey);
        [$images, $quality] = $this->acceptQualityImages(
            $images,
            [],
            ['input_text' => (string)($req['input_text'] ?? ''), 'request_id' => $requestId, 'pattern' => 'P']
        );
        if (!$images) {
            throw new \RuntimeException('写真イラスト化の画像が品質基準を満たしませんでした。再生成してください。');
        }
        $this->opsSvc->record('quality_checked', $requestId, $quality);
        $this->updateRequestStatus($requestId, 'uploading');
        $urls = $this->saveImages($requestId, $promptId, 'P', $images);

        $this->updateRequestStatus($requestId, 'sending');
        $this->safePushImages((string)$req['line_user_id'], "写真のイラスト化が完了しました。\nスタイル: {$label}", $urls, $requestId);
        $this->safePushWithQuickReply((string)$req['line_user_id'], "別の写真もイラスト化できます。写真を送るか、通常の画像生成を選んでください。", [
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '通常生成', 'text' => '生成する']],
        ], $requestId);
        $this->updateRequestStatus($requestId, 'completed');
    }

    private function processTextGeneration(array $req): void {
        $requestId = (int)$req['id'];
        $this->updateRequestStatus($requestId, 'analyzing');

        $surveyContext = [];
        $surveyFile = BASE_PATH . '/app/Services/SurveyDefinition.php';
        if (is_file($surveyFile) && (!empty($req['survey_style']) || !empty($req['survey_mood']))) {
            require_once $surveyFile;
            $styleKey = $req['survey_style'] ?? 'any_style';
            $moodKey = $req['survey_mood'] ?? 'any_mood';
            $surveyContext = [
                'style_prompt' => SurveyDefinition::STYLE_PROMPT[$styleKey] ?? '',
                'mood_prompt' => SurveyDefinition::MOOD_PROMPT[$moodKey] ?? '',
                'stability_preset' => SurveyDefinition::stabilityPreset($styleKey),
            ];
        }

        $promptData = $this->promptSvc->generate($req['input_text'], $surveyContext);
        $configuredPerPattern = max(1, min(4, (int)Settings::get('images_per_pattern', '4')));
        $configuredTotal = max(1, min(8, Settings::maxImagesPerRequest()));
        $targetTotal = min($configuredTotal, $configuredPerPattern * 2);
        $countA = min($configuredPerPattern, (int)ceil($targetTotal / 2));
        $countB = min($configuredPerPattern, max(0, $targetTotal - $countA));

        error_log(sprintf(
            'AI image generation start request_id=%d tenant_id=%s engine=%s model=%s quality=%s target=%d (%d+%d)',
            $requestId,
            Settings::tenantId() !== null ? (string)Settings::tenantId() : 'default',
            trim((string)Settings::get('image_engine', 'stability')) ?: 'stability',
            trim((string)Settings::get('openai_image_model', 'gpt-image-1')),
            trim((string)Settings::get('image_quality_level', 'standard')),
            $targetTotal,
            $countA,
            $countB
        ));

        $promptAId = $this->savePrompt($requestId, 'A', [
            'title_ja' => $promptData['prompt_a_title_ja'] ?? 'Aパターン',
            'input_summary_ja' => $promptData['input_summary_ja'] ?? '',
            'prompt_en' => $promptData['prompt_a_en'] ?? '',
            'safety_notes' => $promptData['safety_notes'] ?? '',
        ]);
        $promptBId = null;
        if ($countB > 0) {
            $promptBId = $this->savePrompt($requestId, 'B', [
                'title_ja' => $promptData['prompt_b_title_ja'] ?? 'Bパターン',
                'input_summary_ja' => $promptData['input_summary_ja'] ?? '',
                'prompt_en' => $promptData['prompt_b_en'] ?? '',
                'safety_notes' => $promptData['safety_notes'] ?? '',
            ]);
        }

        $this->pdo->prepare(
            "UPDATE image_requests SET input_type = ?, updated_at = NOW() WHERE id = ?" .
            $this->tenantWhere('image_requests')
        )->execute(array_merge(
            [$promptData['input_type'] ?? 'simple_keywords', $requestId],
            $this->tenantParams('image_requests')
        ));

        $this->updateRequestStatus($requestId, 'generating');
        $preset = $surveyContext['stability_preset'] ?? 'enhance';
        $this->touchProcessingJob($requestId);
        $fingerprints = [];
        $imagesA = $this->generateQualityChecked(
            (string)($promptData['prompt_a_en'] ?? ''),
            $countA,
            $preset,
            'A',
            $fingerprints,
            ['input_text' => (string)$req['input_text'], 'request_id' => $requestId]
        );
        $urlsA = $this->saveImages($requestId, $promptAId, 'A', $imagesA);
        $this->touchProcessingJob($requestId);

        $urlsB = [];
        if ($countB > 0 && $promptBId !== null) {
            $this->updateRequestStatus($requestId, 'generating');
            $this->touchProcessingJob($requestId);
            $imagesB = $this->generateQualityChecked(
                (string)($promptData['prompt_b_en'] ?? ''),
                $countB,
                $preset,
                'B',
                $fingerprints,
                ['input_text' => (string)$req['input_text'], 'request_id' => $requestId]
            );
            $urlsB = $this->saveImages($requestId, $promptBId, 'B', $imagesB);
            $this->touchProcessingJob($requestId);
        }

        $this->updateRequestStatus($requestId, 'uploading');
        $this->updateRequestStatus($requestId, 'sending');
        $titleA = $promptData['prompt_a_title_ja'] ?? 'Aパターン';
        $titleB = $promptData['prompt_b_title_ja'] ?? 'Bパターン';
        $this->safePushImages((string)$req['line_user_id'], "画像が完成しました。\nまずは「{$titleA}」です。", $urlsA, $requestId);
        if ($urlsB) {
            sleep(1);
            $this->safePushImages((string)$req['line_user_id'], "続いて「{$titleB}」です。", $urlsB, $requestId);
        }
        $this->updateRequestStatus($requestId, 'completed');
    }

    private function generateQualityChecked(
        string $prompt,
        int $targetCount,
        string $preset,
        string $pattern,
        array &$fingerprints,
        array $context
    ): array {
        $accepted = [];
        $rejected = [];
        $maxRegeneration = max(0, min(5, (int)Settings::get('image_quality_max_regeneration_attempts', '2')));
        $rounds = $maxRegeneration + 1;

        for ($round = 0; $round < $rounds && count($accepted) < $targetCount; $round++) {
            $needed = $targetCount - count($accepted);
            $roundPrompt = $this->qualityVariationPrompt($prompt, $pattern, $round);
            $candidates = $this->generateWithRetry($roundPrompt, $needed, $preset);
            [$passed, $inspection] = $this->acceptQualityImages(
                $candidates,
                $fingerprints,
                array_merge($context, ['pattern' => $pattern, 'quality_round' => $round])
            );
            foreach ($passed as $image) {
                if (count($accepted) >= $targetCount) {
                    break;
                }
                $accepted[] = $image;
                $fingerprints[] = $image['_quality_fingerprint'];
                unset($accepted[array_key_last($accepted)]['_quality_fingerprint']);
            }
            $rejected = array_merge($rejected, $inspection['rejected']);
            $this->opsSvc->record('quality_round', (int)($context['request_id'] ?? 0), [
                'pattern' => $pattern,
                'round' => $round + 1,
                'accepted' => count($passed),
                'rejected' => count($inspection['rejected']),
                'reasons' => $inspection['rejected'],
            ]);
        }

        if (!$accepted) {
            throw new \RuntimeException('生成画像が品質基準を満たしませんでした。構図を変えて再度お試しください。');
        }
        if (count($accepted) < $targetCount) {
            $this->opsSvc->record('quality_partial', (int)($context['request_id'] ?? 0), [
                'pattern' => $pattern,
                'target' => $targetCount,
                'accepted' => count($accepted),
                'rejected' => $rejected,
            ]);
        }
        return $accepted;
    }

    private function acceptQualityImages(array $images, array $fingerprints, array $context): array {
        $accepted = [];
        $rejected = [];
        foreach ($images as $image) {
            $result = $this->qualitySvc->inspect($image, $fingerprints, $context);
            if (!$result['accepted']) {
                $rejected[] = implode(' ', $result['reasons']);
                continue;
            }
            $image['_quality_fingerprint'] = $result['fingerprint'];
            $accepted[] = $image;
            $fingerprints[] = $result['fingerprint'];
        }
        return [$accepted, [
            'accepted' => count($accepted),
            'rejected' => $rejected,
        ]];
    }

    private function qualityVariationPrompt(string $prompt, string $pattern, int $round): string {
        $variation = $pattern === 'B'
            ? 'Use a clearly different camera angle, focal length, layout, subject placement and lighting from the first concept.'
            : 'Use a distinct composition with one clear focal point and natural proportions.';
        if ($round > 0) {
            $variation .= ' Regeneration attempt ' . ($round + 1)
                . ': substantially change the viewpoint, distance, pose, background layout and color balance. Do not repeat the previous composition.';
        }
        return trim($prompt . "\n\n" . $variation);
    }

    private function safePushImages(string $lineUserId, string $message, array $urls, int $requestId): bool {
        if (!$this->lineSvc || $lineUserId === '' || !$urls) {
            return false;
        }
        try {
            $sent = $this->lineSvc->pushImages($lineUserId, $message, $urls);
            if (!$sent) {
                throw new \RuntimeException('LINE画像送信に失敗しました。');
            }
            $this->opsSvc?->record('line_images_sent', $requestId, ['count' => count($urls)]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('worker', 'LINE画像送信失敗: ' . $e->getMessage(), $requestId);
            $this->opsSvc?->record('line_delivery_failed', $requestId, [
                'type' => 'images',
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function safePushWithQuickReply(string $lineUserId, string $message, array $items, int $requestId): bool {
        if (!$this->lineSvc || $lineUserId === '') {
            return false;
        }
        try {
            $sent = $this->lineSvc->pushWithQuickReply($lineUserId, $message, $items);
            if (!$sent) {
                throw new \RuntimeException('LINEクイックリプライ送信に失敗しました。');
            }
            return true;
        } catch (\Throwable $e) {
            Logger::error('worker', 'LINEクイックリプライ送信失敗: ' . $e->getMessage(), $requestId);
            $this->opsSvc?->record('line_delivery_failed', $requestId, [
                'type' => 'quick_reply',
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function safePushText(string $lineUserId, string $message, int $requestId): bool {
        if (!$this->lineSvc || $lineUserId === '') {
            return false;
        }
        try {
            $sent = $this->lineSvc->pushText($lineUserId, $message);
            if (!$sent) {
                throw new \RuntimeException('LINEテキスト送信に失敗しました。');
            }
            return true;
        } catch (\Throwable $e) {
            Logger::error('worker', 'LINEテキスト送信失敗: ' . $e->getMessage(), $requestId);
            $this->opsSvc?->record('line_delivery_failed', $requestId, [
                'type' => 'text',
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function staleMinutes(): int {
        return max(5, min(120, (int)Settings::get('generation_stale_minutes', '10')));
    }

    private function generateWithRetry(string $prompt, int $count, string $preset = 'enhance'): array {
        $lastErr = null;
        for ($i = 0; $i < 2; $i++) {
            try {
                return $this->imageSvc->generate($prompt, $count, $preset);
            } catch (\Throwable $e) {
                $lastErr = $e;
                if (!$this->isRetryableGenerationError($e) || $i >= 1) {
                    throw $e;
                }
                sleep(3);
            }
        }
        throw $lastErr;
    }

    private function isRetryableGenerationError(\Throwable $e): bool {
        $message = strtolower($e->getMessage());
        foreach (['401', '403', '400', 'invalid api key', 'incorrect api key', 'authentication', 'unauthorized', 'bad request'] as $needle) {
            if (strpos($message, $needle) !== false) {
                return false;
            }
        }
        foreach (['timeout', 'timed out', '429', '500', '502', '503', '504', 'temporarily', 'connection', 'curl', 'rate limit'] as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function clearIncompleteArtifacts(int $requestId): void {
        try {
            $this->pdo->prepare(
                "DELETE FROM generated_images WHERE request_id = ?" . $this->tenantWhere('generated_images')
            )->execute(array_merge([$requestId], $this->tenantParams('generated_images')));
        } catch (\Throwable $e) {
        }
        try {
            $this->pdo->prepare(
                "DELETE FROM prompts WHERE request_id = ?" . $this->tenantWhere('prompts')
            )->execute(array_merge([$requestId], $this->tenantParams('prompts')));
        } catch (\Throwable $e) {
        }
    }

    private function saveImages(int $requestId, int $promptId, string $type, array $images): array {
        $urls = [];
        foreach ($images as $i => $img) {
            $no = $i + 1;
            $ext = $img['ext'] ?? 'png';
            $path = "images/{$requestId}/" . strtolower($type) . "_{$no}.{$ext}";
            $url = $this->storageSvc->save($img['data'], $path);
            $this->insertTenantRecord('generated_images', [
                'request_id' => $requestId,
                'prompt_id' => $promptId,
                'prompt_type' => $type,
                'image_no' => $no,
                'image_url' => $url,
                'preview_url' => $url,
                'storage_path' => $path,
                'status' => 'completed',
            ], false);
            $urls[] = $url;
        }
        return $urls;
    }

    private function savePrompt(int $requestId, string $type, array $data): int {
        return $this->insertTenantRecord('prompts', [
            'request_id' => $requestId,
            'prompt_type' => $type,
            'title_ja' => $data['title_ja'] ?? '',
            'input_summary_ja' => $data['input_summary_ja'] ?? '',
            'prompt_en' => $data['prompt_en'] ?? '',
            'safety_notes' => $data['safety_notes'] ?? '',
        ], false);
    }

    private function handleFailure(array $job, int $requestId, \Throwable $e): void {
        $retry = (int)$job['retry_count'] + 1;
        $message = $this->generationContext() . ' ' . $e->getMessage();
        Logger::error('worker', "job failed request_id={$requestId}: " . $message, $requestId);
        if ($retry <= 2 && $this->isRetryableGenerationError($e)) {
            $this->pdo->prepare("
                UPDATE job_queue
                SET status = 'pending', retry_count = ?, error_message = ?, available_at = DATE_ADD(NOW(), INTERVAL 30 SECOND), updated_at = NOW()
                WHERE id = ?
            ")->execute([$retry, $message, $job['id']]);
            $this->opsSvc?->record('job_retry_scheduled', $requestId, [
                'job_id' => (int)$job['id'],
                'retry' => $retry,
                'message' => $message,
            ]);
            return;
        }

        $this->pdo->prepare("UPDATE job_queue SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$message, $job['id']]);
        $this->updateRequestStatus($requestId, 'failed', $message);
        $this->opsSvc?->record('job_failed', $requestId, [
            'job_id' => (int)$job['id'],
            'retry' => $retry,
            'message' => $message,
        ]);

        $req = $this->getRequest($requestId);
        if ($req && $this->lineSvc) {
            $this->safePushText((string)$req['line_user_id'], "画像生成中にエラーが発生しました。\n時間を置いてもう一度お試しください。", $requestId);
        }
    }

    private function touchProcessingJob(int $requestId): void {
        try {
            $this->pdo->prepare(
                "UPDATE job_queue SET updated_at = NOW() WHERE request_id = ? AND status = 'processing'"
                . $this->tenantWhere('job_queue')
            )->execute(array_merge([$requestId], $this->tenantParams('job_queue')));
            $this->opsSvc?->heartbeat(['event' => 'job_heartbeat', 'request_id' => $requestId]);
        } catch (\Throwable $e) {
        }
    }

    private function generationContext(): string {
        $tenant = Settings::tenantId() !== null ? (string)Settings::tenantId() : 'default';
        $engine = trim((string)Settings::get('image_engine', 'stability')) ?: 'stability';
        $model = $engine === 'openai'
            ? trim((string)Settings::get('openai_image_model', 'gpt-image-1'))
            : ($engine === 'grok'
                ? trim((string)Settings::get('grok_image_model', 'grok-imagine-image'))
                : trim((string)Settings::get('stability_model', 'sdxl')));
        $quality = trim((string)Settings::get('image_quality_level', 'standard'));
        $perPattern = max(1, min(4, (int)Settings::get('images_per_pattern', '4')));
        $target = min(max(1, min(8, Settings::maxImagesPerRequest())), $perPattern * 2);
        $openai = trim((string)Settings::get('openai_api_key', '')) !== '' ? 'set' : 'missing';
        $stability = trim((string)Settings::get('stability_api_key', '')) !== '' ? 'set' : 'missing';
        $grok = trim((string)Settings::get('grok_api_key', '')) !== '' ? 'set' : 'missing';

        return sprintf(
            '[tenant=%s engine=%s model=%s quality=%s target=%d keys(openai=%s,stability=%s,grok=%s)]',
            $tenant,
            $engine,
            $model,
            $quality,
            $target,
            $openai,
            $stability,
            $grok
        );
    }

    private function updateRequestStatus(int $id, string $status, ?string $error = null): void {
        $this->pdo->prepare(
            "UPDATE image_requests SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?" .
            $this->tenantWhere('image_requests')
        )->execute(array_merge(
            [$status, $error, $id],
            $this->tenantParams('image_requests')
        ));
    }

    private function getRequest(int $id): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM image_requests WHERE id = ?" . $this->tenantWhere('image_requests')
        );
        $stmt->execute(array_merge([$id], $this->tenantParams('image_requests')));
        return $stmt->fetch() ?: null;
    }

    private function tenantWhere(string $table): string {
        return $this->tenant ? $this->tenant->andWhere($table) : '';
    }

    private function tenantParams(string $table): array {
        return $this->tenant ? $this->tenant->params($table) : [];
    }

    private function insertTenantRecord(string $table, array $data, bool $withUpdatedAt = true): int {
        if (!$this->tenant) {
            throw new RuntimeException('Tenant scope is not initialized.');
        }
        [$columns, $values] = $this->tenant->assignInsert(
            $table,
            array_keys($data),
            array_values($data)
        );
        $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $timestampColumns = $withUpdatedAt ? ', created_at, updated_at' : ', created_at';
        $timestampValues = $withUpdatedAt ? ', NOW(), NOW()' : ', NOW()';
        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedColumns) . $timestampColumns
            . ') VALUES (' . $placeholders . $timestampValues . ')';
        $this->pdo->prepare($sql)->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    private function columnExists(string $table, string $column): bool {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
            ");
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function photoStyleLabel(string $styleKey): string {
        $labels = [
            'anime' => 'アニメ風',
            'watercolor' => '水彩風',
            'picture_book' => '絵本風',
            'sns_icon' => 'SNSアイコン風',
            'japanese' => '和風イラスト',
            'pastel' => 'パステル風',
            'line_art' => '線画風',
        ];
        return $labels[$styleKey] ?? 'アニメ風';
    }

    private function ensurePhotoColumns(): void {
        $columns = [
            "ALTER TABLE image_requests ADD COLUMN source_image_message_id VARCHAR(255) NULL AFTER input_text",
            "ALTER TABLE image_requests ADD COLUMN source_image_path TEXT NULL AFTER source_image_message_id",
            "ALTER TABLE image_requests ADD COLUMN source_image_url TEXT NULL AFTER source_image_path",
            "ALTER TABLE image_requests ADD COLUMN photo_style VARCHAR(50) NULL AFTER source_image_url",
        ];
        foreach ($columns as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (\Throwable $e) {
                // Existing columns are expected after the first run.
            }
        }
    }
}
