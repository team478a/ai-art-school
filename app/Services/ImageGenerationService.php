<?php
// app/Services/ImageGenerationService.php

require_once BASE_PATH . '/config/settings.php';

class ImageGenerationService {
    private string $engine;

    public function __construct() {
        $this->engine = strtolower(trim(Settings::get('image_engine', 'stability')));
    }

    /**
     * @return array<int,array{data:string,ext:string}>
     */
    public function generate(string $promptEn, int $count = 4, string $stylePreset = 'enhance', string $mode = 'normal'): array {
        $promptEn = $this->enhancePrompt($promptEn);
        $stylePreset = $this->normalizeStylePreset($stylePreset, $promptEn);
        $stylePreset = $this->faceSafeStylePreset($stylePreset, $promptEn);
        $engine = $this->resolveEngine($mode, $promptEn);
        $enginePrompt = $this->preparePromptForEngine($engine, $promptEn);

        try {
            return $this->generateWithEngine($engine, $enginePrompt, $count, $stylePreset);
        } catch (RuntimeException $e) {
            $qualitySensitive = $this->isQualitySensitivePrompt($promptEn);
            $allowQualityDowngrade = Settings::get('image_quality_allow_downgrade', '0') === '1';
            $fallback = $this->fallbackEngineExcept($engine);
            $canFallback = (!$qualitySensitive || $allowQualityDowngrade)
                && ($this->shouldFallbackOnGenerationError($e)
                    || ($engine === 'stability' && $this->isStabilityBalanceError($e)));
            if ($fallback !== null && $canFallback) {
                $this->recordRequestFallback($engine, $fallback, $e->getMessage());
                $fallbackPrompt = $this->preparePromptForEngine($fallback, $promptEn);
                return $this->generateWithEngine($fallback, $fallbackPrompt, $count, $stylePreset);
            }

            if ($qualitySensitive && $engine === 'openai' && !$allowQualityDowngrade) {
                throw new RuntimeException(
                    '人物向け高品質生成に失敗しました。品質低下を防ぐため、別エンジンへの自動切替は行っていません。原因: '
                    . $e->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * @return array<int,array{data:string,ext:string}>
     */
    public function generateFromImage(string $imagePath, string $styleKey = 'anime'): array {
        if (!is_file($imagePath)) {
            throw new RuntimeException('元画像が見つかりません。');
        }
        if (!$this->openAIApiKey()) {
            throw new RuntimeException('写真イラスト化にはOpenAI APIキーが必要です。');
        }

        $prompt = $this->photoIllustrationPrompt($styleKey);
        $body = [
            'model' => $this->openAIImageModel(),
            'prompt' => $prompt,
            'size' => $this->openAIPhotoSize(),
            'n' => 1,
            'image' => new CURLFile($imagePath, $this->detectMimeType($imagePath), basename($imagePath)),
        ];

        $data = $this->requestMultipart('https://api.openai.com/v1/images/edits', $this->openAIApiKey(), $body, 'OpenAI');
        $images = $this->decodeOpenAIImages($data);
        if (!$images) {
            throw new RuntimeException('写真イラスト化の画像が生成されませんでした。');
        }
        return $images;
    }

    private function resolveEngine(string $mode, string $promptEn): string {
        $engine = $this->engine ?: 'stability';
        $quality = strtolower(trim(Settings::get('image_quality_level', 'premium')));
        $humanSafeEngine = strtolower(trim(Settings::get('image_human_safe_engine', 'openai')));
        $qualityFirst = in_array($quality, ['high', 'premium', 'max'], true);

        if ($qualityFirst && $this->isQualitySensitivePrompt($promptEn) && $this->engineReady('openai')) {
            return 'openai';
        }

        if (($mode === 'high_quality' || $quality === 'max') && $this->openAIApiKey()) {
            $preferred = strtolower(trim(Settings::get('image_high_quality_engine', 'openai'))) ?: 'openai';
            if ($this->engineReady($preferred)) {
                return $preferred;
            }
            return 'openai';
        }

        if ($humanSafeEngine !== '' && $this->isQualitySensitivePrompt($promptEn)) {
            if ($this->engineReady($humanSafeEngine)) {
                return $humanSafeEngine;
            }
            if ($this->engineReady('openai')) {
                return 'openai';
            }
        }

        if ($engine === 'openai' && !$this->openAIApiKey()) {
            if (Settings::stabilityApiKey()) {
                return 'stability';
            }
            throw new RuntimeException('OpenAI APIキーが設定されていません。');
        }

        if ($engine === 'stability' && $this->stabilityBalanceIsLow()) {
            $fallback = $this->fallbackEngine();
            if ($fallback !== null) {
                $this->recordRequestFallback(
                    'stability',
                    $fallback,
                    'cached_credits=' . Settings::get('stability_credits_cache', '')
                );
                return $fallback;
            }
        }

        return $engine;
    }

    private function generateWithEngine(string $engine, string $promptEn, int $count, string $stylePreset): array {
        if ($engine === 'openai') {
            return $this->generateWithOpenAI($promptEn, $count, $this->openAIImageSize());
        }
        if ($engine === 'grok') {
            return $this->generateWithGrok($promptEn, $count);
        }
        return $this->generateWithStability($promptEn, $count, $stylePreset);
    }

    private function stabilityBalanceIsLow(): bool {
        if (Settings::get('stability_auto_switch_enabled', '1') === '0') {
            return false;
        }
        $credits = Settings::get('stability_credits_cache', '');
        if (!is_numeric($credits)) {
            return false;
        }
        $threshold = (float)Settings::get('stability_auto_switch_threshold', '1');
        return (float)$credits <= $threshold;
    }

    private function fallbackEngine(): ?string {
        $preferred = strtolower(trim(Settings::get('stability_fallback_engine', 'openai')));
        $candidates = array_values(array_unique([$preferred, 'openai', 'grok']));
        foreach ($candidates as $engine) {
            if ($engine === 'openai' && $this->openAIApiKey()) {
                return 'openai';
            }
            if ($engine === 'grok' && trim(Settings::get('grok_api_key', '')) !== '') {
                return 'grok';
            }
        }
        return null;
    }

    private function fallbackEngineExcept(string $current): ?string {
        if (Settings::get('stability_auto_switch_enabled', '1') === '0') {
            return null;
        }
        $preferred = strtolower(trim(Settings::get('stability_fallback_engine', 'openai')));
        $candidates = array_values(array_unique([$preferred, 'openai', 'grok', 'stability']));
        foreach ($candidates as $engine) {
            if ($engine === $current) {
                continue;
            }
            if ($this->engineReady($engine)) {
                return $engine;
            }
        }
        return null;
    }

    private function engineReady(string $engine): bool {
        if ($engine === 'openai') {
            return $this->openAIApiKey() !== '';
        }
        if ($engine === 'grok') {
            return trim(Settings::get('grok_api_key', '')) !== '';
        }
        if ($engine === 'stability') {
            return trim(Settings::stabilityApiKey()) !== '';
        }
        return false;
    }

    private function shouldFallbackOnGenerationError(RuntimeException $e): bool {
        $msg = strtolower($e->getMessage());
        foreach (['timeout', 'temporarily', 'rate limit', '429', '500', '502', '503', '504', 'quota', 'credit', 'balance', 'billing', 'connection', 'curl'] as $needle) {
            if (strpos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isStabilityBalanceError(RuntimeException $e): bool {
        $msg = strtolower($e->getMessage());
        foreach (['402', 'credit', 'credits', 'balance', 'payment', 'billing', 'quota', 'insufficient'] as $needle) {
            if (strpos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function generateWithOpenAI(string $promptEn, int $count, string $size): array {
        if (!$this->openAIApiKey()) {
            throw new RuntimeException('OpenAI APIキーが設定されていません。');
        }

        $images = [];
        $remaining = max(1, $count);
        while ($remaining > 0) {
            $batch = min($remaining, 4);
            $body = [
                'model' => $this->openAIImageModel(),
                'prompt' => $promptEn,
                'size' => $size,
                'n' => $batch,
            ];

            $quality = trim(Settings::get('openai_image_quality', ''));
            if ($quality === '' && stripos($this->openAIImageModel(), 'gpt-image-1') !== false) {
                $quality = 'high';
            }
            if ($quality !== '') {
                $body['quality'] = $quality;
            }

            $data = $this->requestJson('https://api.openai.com/v1/images/generations', $this->openAIApiKey(), $body, 'OpenAI');
            foreach ($this->decodeOpenAIImages($data) as $image) {
                $images[] = $image;
            }
            $remaining -= $batch;
        }

        if (!$images) {
            throw new RuntimeException('画像が生成されませんでした（OpenAI）。');
        }
        return $images;
    }

    private function generateWithStability(string $promptEn, int $count, string $stylePreset): array {
        $apiKey = Settings::stabilityApiKey();
        if (!$apiKey) {
            throw new RuntimeException('Stability AI APIキーが設定されていません。');
        }

        $model = Settings::get('stability_model', 'sdxl');
        $aspect = Settings::get('image_aspect', 'square');
        [$w, $h, $arRatio] = $this->aspectDimensions($aspect);

        $steps = (int) Settings::get('image_steps', '40');
        $cfgScale = (float) Settings::get('image_cfg', '7');
        $steps = max(30, min(50, $steps));
        $cfgScale = max(5.0, min(9.0, $cfgScale));

        $negative = $this->negativePrompt($promptEn);
        if ($this->containsHumanSubject($promptEn)) {
            $cfgScale = min($cfgScale, 7.0);
        }

        if ($model === 'core' || $model === 'ultra') {
            return $this->generateWithStabilityV2($apiKey, $promptEn, $count, $model, $arRatio, $negative);
        }

        $apiUrl = 'https://api.stability.ai/v1/generation/stable-diffusion-xl-1024-v1-0/text-to-image';
        $body = json_encode([
            'text_prompts' => [
                ['text' => $promptEn, 'weight' => 1],
                ['text' => $negative, 'weight' => -1],
            ],
            'cfg_scale'    => $cfgScale,
            'height'       => $h,
            'width'        => $w,
            'samples'      => $count,
            'steps'        => $steps,
            'style_preset' => $stylePreset,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 150,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Stability AI curl error: {$err}");
        }
        if ($code !== 200) {
            $errBody = json_decode((string)$res, true);
            $msg = $errBody['message'] ?? $res;
            throw new RuntimeException("Stability AI HTTP {$code}: {$msg}");
        }

        $data = json_decode((string)$res, true);
        $images = [];
        foreach (($data['artifacts'] ?? []) as $artifact) {
            if (($artifact['finishReason'] ?? '') === 'SUCCESS' && !empty($artifact['base64'])) {
                $images[] = ['data' => base64_decode($artifact['base64']), 'ext' => 'png'];
            }
        }
        if (!$images) {
            throw new RuntimeException('画像が生成されませんでした（Stability AI）。');
        }
        return $images;
    }

    private function generateWithStabilityV2(string $apiKey, string $promptEn, int $count, string $model, string $aspectRatio, string $negative): array {
        $endpoint = $model === 'ultra'
            ? 'https://api.stability.ai/v2beta/stable-image/generate/ultra'
            : 'https://api.stability.ai/v2beta/stable-image/generate/core';

        $images = [];
        for ($i = 0; $i < $count; $i++) {
            $post = [
                'prompt'          => $promptEn,
                'negative_prompt' => $negative,
                'aspect_ratio'    => $aspectRatio,
                'output_format'   => 'png',
            ];

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Accept: image/*',
                    "Authorization: Bearer {$apiKey}",
                ],
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_TIMEOUT        => 150,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                throw new RuntimeException("Stability AI curl error: {$err}");
            }
            if ($code === 200 && $res) {
                $images[] = ['data' => $res, 'ext' => 'png'];
                continue;
            }

            $json = json_decode((string)$res, true);
            $msg = $json['message'] ?? $json['errors'][0] ?? $res;
            throw new RuntimeException("Stability AI HTTP {$code}: {$msg}");
        }

        if (!$images) {
            throw new RuntimeException("画像が生成されませんでした（Stability {$model}）。");
        }
        return $images;
    }

    private function generateWithGrok(string $promptEn, int $count): array {
        $apiKey = Settings::get('grok_api_key', '');
        if (!$apiKey) {
            throw new RuntimeException('Grok APIキーが設定されていません。');
        }

        $model = Settings::get('grok_image_model', 'grok-imagine-image');
        $apiUrl = 'https://api.x.ai/v1/images/generations';
        $images = [];
        $remaining = $count;

        while ($remaining > 0) {
            $batch = min($remaining, 4);
            $body = json_encode([
                'model'           => $model,
                'prompt'          => $promptEn,
                'n'               => $batch,
                'response_format' => 'b64_json',
            ], JSON_UNESCAPED_UNICODE);

            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    "Authorization: Bearer {$apiKey}",
                ],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => 150,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                throw new RuntimeException("Grok curl error: {$err}");
            }
            if ($code !== 200) {
                $errBody = json_decode((string)$res, true);
                $msg = $errBody['error']['message'] ?? $errBody['error'] ?? $res;
                if (is_array($msg)) {
                    $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
                }
                throw new RuntimeException("Grok HTTP {$code}: {$msg}");
            }

            $data = json_decode((string)$res, true);
            foreach (($data['data'] ?? []) as $item) {
                if (!empty($item['b64_json'])) {
                    $images[] = ['data' => base64_decode($item['b64_json']), 'ext' => 'png'];
                } elseif (!empty($item['url'])) {
                    $bin = @file_get_contents($item['url']);
                    if ($bin !== false) {
                        $images[] = ['data' => $bin, 'ext' => 'png'];
                    }
                }
            }

            $remaining -= $batch;
        }

        if (!$images) {
            throw new RuntimeException('画像が生成されませんでした（Grok）。');
        }
        return $images;
    }

    private function requestJson(string $url, string $apiKey, array $body, string $label): array {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 180,
        ]);
        return $this->finishJsonRequest($ch, $label);
    }

    private function requestMultipart(string $url, string $apiKey, array $body, string $label): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 180,
        ]);
        return $this->finishJsonRequest($ch, $label);
    }

    private function finishJsonRequest($ch, string $label): array {
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("{$label} curl error: {$err}");
        }

        $data = json_decode((string)$res, true);
        if ($code < 200 || $code >= 300) {
            $msg = $data['error']['message'] ?? $data['message'] ?? $res;
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            throw new RuntimeException("{$label} HTTP {$code}: {$msg}");
        }
        if (!is_array($data)) {
            throw new RuntimeException("{$label} returned invalid JSON.");
        }
        return $data;
    }

    private function decodeOpenAIImages(array $data): array {
        $images = [];
        foreach (($data['data'] ?? []) as $item) {
            if (!empty($item['b64_json'])) {
                $images[] = ['data' => base64_decode($item['b64_json']), 'ext' => 'png'];
                continue;
            }
            if (!empty($item['url'])) {
                $bin = @file_get_contents($item['url']);
                if ($bin !== false) {
                    $images[] = ['data' => $bin, 'ext' => 'png'];
                }
            }
        }
        return $images;
    }

    private function aspectDimensions(string $aspect): array {
        switch ($aspect) {
            case 'portrait':
                return [832, 1216, '2:3'];
            case 'landscape':
                return [1216, 832, '3:2'];
            default:
                return [1024, 1024, '1:1'];
        }
    }

    private function enhancePrompt(string $prompt): string {
        $prompt = trim($prompt);
        if ($this->requestsNoHumanSubject($prompt)) {
            $isVariantB = stripos($prompt, 'VARIANT B') !== false;
            $composition = $isVariantB
                ? 'very wide elevated panoramic environmental composition, broad landscape depth, no macro or close-up framing'
                : 'intimate ground-level environmental composition, large readable foreground botanical cluster, no aerial or panoramic viewpoint';
            $parts = [
                'STRICT COMPOSITION: scenery and environment only',
                'no humans, no people, no children, no faces, no portraits, no human silhouettes',
                'no fairies, no angels, no humanoid or anthropomorphic characters',
                rtrim($prompt, " ,."),
                $composition,
                'botanical winged sprouts with leaf-like wings only, never a person or creature',
                'coherent green and blue palette',
                'sparkling natural light and a subtle rainbow',
                'realistic materials and botanical detail',
                'no text',
                'no logo',
                'no watermark',
            ];
            return implode(', ', array_values(array_unique($parts))) . '.';
        }

        $quality = strtolower(trim(Settings::get('image_quality_level', 'premium')));
        $isHuman = $this->containsHumanSubject($prompt);
        $isChild = $this->containsChildSubject($prompt);
        $parts = [
            rtrim($prompt, " ,."),
            'clear main subject',
            'coherent composition',
            'refined colors',
            'professional soft lighting',
            'polished high-resolution illustration',
            'no text',
            'no watermark',
        ];

        if ($isHuman) {
            $parts[] = 'one main person or at most two clearly separated people';
            $parts[] = 'medium or waist-up composition';
            $parts[] = 'natural readable face with balanced eyes and a gentle expression';
            $parts[] = 'calm stable pose';
            $parts[] = 'hands resting naturally, partly hidden, or outside the frame';
        }
        if ($isChild) {
            $parts[] = 'wholesome age-appropriate child illustration';
            $parts[] = 'warm friendly eyes and natural child proportions';
        }
        if ($quality === 'max') {
            $parts[] = 'carefully balanced foreground and background';
            $parts[] = 'gallery-quality finish';
        }

        return implode(', ', array_values(array_unique($parts))) . '.';
    }

    private function preparePromptForEngine(string $engine, string $prompt): string {
        if ($this->requestsNoHumanSubject($prompt)) {
            return rtrim($prompt, " ,.")
                . ', scenery and environment only, no central person-shaped subject, botanical non-humanoid forms only.';
        }

        if ($engine !== 'stability' || !$this->isQualitySensitivePrompt($prompt)) {
            return $prompt;
        }

        return rtrim($prompt, " ,.")
            . ', simple stable pose, face unobstructed, minimal visible fingers, no crowd, no extreme perspective.';
    }

    private function isQualitySensitivePrompt(string $prompt): bool {
        if ($this->requestsNoHumanSubject($prompt)) {
            return false;
        }
        return $this->containsHumanSubject($prompt) || $this->containsHumanActivityContext($prompt);
    }

    private function containsHumanActivityContext(string $prompt): bool {
        if ($this->requestsNoHumanSubject($prompt)) {
            return false;
        }
        $p = function_exists('mb_strtolower') ? mb_strtolower($prompt, 'UTF-8') : strtolower($prompt);
        $needles = [
            'birthday', 'wedding', 'camping', 'barbecue', 'bbq', 'playground', 'school event',
            'sports day', 'festival', 'swimming', 'family trip', 'dance', 'party',
            '誕生日', '結婚式', 'キャンプ', 'バーベキュー', '公園', '運動会', '祭り',
            '海水浴', '水泳', '家族旅行', 'ダンス', 'パーティー', '教室', '入学式', '卒業式',
        ];
        foreach ($needles as $needle) {
            if (strpos($p, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function containsHumanSubject(string $prompt): bool {
        if ($this->requestsNoHumanSubject($prompt)) {
            return false;
        }
        $p = function_exists('mb_strtolower') ? mb_strtolower($prompt, 'UTF-8') : strtolower($prompt);
        $needles = [
            'person', 'people', 'human', 'girl', 'boy', 'child', 'children', 'kid', 'kids',
            'baby', 'woman', 'man', 'lady', 'family', 'portrait', 'face', 'smile',
            'mother', 'father', 'daughter', 'son', 'bride', 'student',
            '人物', '人', '女性', '男性', '女の子', '男の子', '子ども', '子供', 'こども',
            '赤ちゃん', '親子', '家族', '母', '父', '娘', '息子', '顔', '笑顔', '少女', '少年', '幼児',
        ];
        foreach ($needles as $needle) {
            if (strpos($p, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function containsChildSubject(string $prompt): bool {
        if ($this->requestsNoHumanSubject($prompt)) {
            return false;
        }
        $p = function_exists('mb_strtolower') ? mb_strtolower($prompt, 'UTF-8') : strtolower($prompt);
        $needles = [
            'girl', 'boy', 'child', 'children', 'kid', 'kids', 'baby', 'toddler', 'one-year-old',
            '1-year-old', 'daughter', 'son', '女の子', '男の子', '子ども', '子供', 'こども',
            '赤ちゃん', '幼児', '1歳', '一歳', '娘', '息子', '少女', '少年',
        ];
        foreach ($needles as $needle) {
            if (strpos($p, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function requestsNoHumanSubject(string $prompt): bool {
        $p = function_exists('mb_strtolower') ? mb_strtolower($prompt, 'UTF-8') : strtolower($prompt);
        $needles = [
            '人物なし', '人物はなし', '人物を入れない', '人物を描かない', '人物を含めない', '人物不要', '人物のいない',
            '人なし', '人はなし', '人を入れない', '人を描かない', '人を含めない', '人のいない',
            'no people', 'no person', 'no persons', 'no human', 'no humans',
            'without people', 'without a person', 'without humans', 'people-free', 'human-free',
            'scenery only', 'landscape only', 'environment only',
        ];
        foreach ($needles as $needle) {
            if (strpos($p, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function recordRequestFallback(string $from, string $to, string $reason): void {
        $message = preg_replace('/\s+/u', ' ', trim($reason));
        $message = substr((string)$message, 0, 500);
        Settings::set('image_last_request_fallback_at', date('Y-m-d H:i:s'));
        Settings::set('image_last_request_fallback_from', $from);
        Settings::set('image_last_request_fallback_to', $to);
        Settings::set('image_last_request_fallback_reason', $message);
        error_log("ImageGenerationService: request fallback {$from} -> {$to}: {$message}");
    }

    private function faceSafeStylePreset(string $stylePreset, string $prompt): string {
        if (!$this->containsHumanSubject($prompt)) {
            return $stylePreset;
        }

        $p = strtolower($prompt);
        $explicitPhoto = strpos($p, 'photorealistic') !== false
            || strpos($p, 'realistic photo') !== false
            || strpos($p, 'photograph') !== false
            || strpos($p, 'photo-realistic') !== false;

        if (!$explicitPhoto && ($stylePreset === 'photographic' || $stylePreset === 'enhance')) {
            return 'anime';
        }

        return $stylePreset;
    }

    private function normalizeStylePreset(string $stylePreset, string $prompt): string {
        $stylePreset = strtolower(trim($stylePreset));
        $allowed = [
            '3d-model', 'analog-film', 'anime', 'cinematic', 'comic-book',
            'digital-art', 'enhance', 'fantasy-art', 'isometric', 'line-art',
            'low-poly', 'modeling-compound', 'neon-punk', 'origami',
            'photographic', 'pixel-art', 'tile-texture',
        ];

        if (in_array($stylePreset, $allowed, true)) {
            return $stylePreset;
        }

        $p = strtolower($prompt . ' ' . $stylePreset);
        if (strpos($p, 'anime') !== false || strpos($p, 'manga') !== false || strpos($p, 'kawaii') !== false) {
            return 'anime';
        }
        if (strpos($p, 'photo') !== false || strpos($p, 'realistic') !== false) {
            return 'photographic';
        }
        if (strpos($p, 'comic') !== false) {
            return 'comic-book';
        }
        if (strpos($p, 'line') !== false || strpos($p, 'ink') !== false) {
            return 'line-art';
        }
        if (strpos($p, 'fantasy') !== false || strpos($p, 'magical') !== false) {
            return 'fantasy-art';
        }
        if (strpos($p, 'film') !== false || strpos($p, 'cinematic') !== false) {
            return 'cinematic';
        }
        return 'enhance';
    }

    private function negativePrompt(string $prompt = ''): string {
        $negative = 'blurry, low quality, worst quality, bad quality, lowres, jpeg artifacts, noise, overexposed, underexposed, muddy colors, flat lighting, dull composition, cropped, out of frame, duplicate, error, watermark, text, signature, logo, username, extra limbs, missing limbs, extra arms, missing arms, extra hands, missing hands, three hands, three arms, extra fingers, missing fingers, too many fingers, fused fingers, webbed fingers, broken fingers, malformed fingers, duplicated fingers, floating fingers, claw hands, twisted wrists, bad hands, mutated hands, bad anatomy, bad proportions, deformed, disfigured, poorly drawn face, ugly face, scary face, creepy smile, horror face, grotesque face, uncanny face, asymmetrical face, asymmetrical eyes, crossed eyes, uneven eyes, bad eyes, deformed eyes, empty eyes, dead eyes, distorted eyes, misaligned pupils, distorted face, warped face, melted face, melted features, extra face, duplicate face, multiple faces, malformed mouth, bad teeth, extra teeth, deformed teeth, open mouth distortion, malformed limbs, extra legs, missing legs, bad feet, malformed feet, extra toes, missing toes, creepy, uncanny, tiny face, distant face, crowd, fused bodies, duplicate child, duplicated person, child with adult face, old-looking child, doll-like skin, plastic skin, hyperrealistic uncanny, photorealistic uncanny, photorealistic child, bad smile, hands covering face, complex finger pose, twisted torso, broken neck, detached limbs, distorted child face, scary child, adult face on child, revealing child outfit, child bikini, extra person, unwanted second person, duplicated subject';
        if ($this->requestsNoHumanSubject($prompt)) {
            $negative .= ', person, people, human, humans, child, children, girl, boy, woman, man, baby, face, portrait, human body, human silhouette, humanoid, fairy, angel, character, anthropomorphic figure, person-shaped subject';
        }
        $custom = trim(Settings::get('ng_words', ''));
        if ($custom !== '') {
            $negative .= ', ' . $custom;
        }
        return $negative;
    }

    private function photoIllustrationPrompt(string $styleKey): string {
        $style = strtolower(trim($styleKey));
        if ($style === 'watercolor') {
            return 'Transform the person in the photo into a bright, friendly watercolor illustration. Preserve the person identity, hairstyle, facial impression, pose, and clothing. Use soft colors, clean composition, natural anatomy, coherent facial features, relaxed simple hands if visible, exactly five fingers per visible hand, and no text.';
        }
        if ($style === 'portrait') {
            return 'Transform the person in the photo into a polished portrait illustration. Preserve identity, hairstyle, facial impression, pose, and clothing. Use natural proportions, clean facial details, symmetrical natural eyes, accurate relaxed hands if visible, exactly five fingers per visible hand, and no text.';
        }
        return 'Transform the person in the photo into a charming anime-style illustration. Preserve identity, hairstyle, facial impression, pose, and clothing. Keep natural body proportions, coherent facial features, accurate relaxed hands if visible, exactly five fingers per visible hand, clean lines, soft lighting, and no text.';
    }

    private function detectMimeType(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return 'image/jpeg';
        }
        if ($ext === 'webp') {
            return 'image/webp';
        }
        return 'image/png';
    }

    private function openAIApiKey(): string {
        if (method_exists('Settings', 'openaiApiKey')) {
            return Settings::openaiApiKey();
        }
        return Settings::get('openai_api_key', '');
    }

    private function openAIImageModel(): string {
        if (method_exists('Settings', 'openaiImageModel')) {
            return Settings::openaiImageModel();
        }
        return Settings::get('openai_image_model', 'gpt-image-1');
    }

    private function openAIImageSize(): string {
        return Settings::get('openai_image_size', Settings::get('photo_illustration_size', '1024x1024'));
    }

    private function openAIPhotoSize(): string {
        return Settings::get('photo_illustration_size', '1024x1024');
    }
}
