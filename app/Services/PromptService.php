<?php
// app/Services/PromptService.php

require_once BASE_PATH . '/config/settings.php';

class PromptService {
    private string $provider;
    private string $openaiApiKey;
    private string $openaiModel;

    public function __construct() {
        $this->openaiApiKey = trim(Settings::get('openai_api_key', ''));
        $this->openaiModel = trim(Settings::get('openai_prompt_model', 'gpt-4.1-mini')) ?: 'gpt-4.1-mini';
        $this->provider = $this->openaiApiKey !== '' ? 'openai' : 'local';
    }

    public function generate(string $inputText, array $surveyContext = []): ?array {
        $styleHint = trim((string)($surveyContext['style_prompt'] ?? ''));
        $moodHint = trim((string)($surveyContext['mood_prompt'] ?? ''));
        $contextNote = '';
        if ($styleHint !== '' || $moodHint !== '') {
            $contextNote = "\nStudent selections:\n"
                . ($styleHint !== '' ? "- Style: {$styleHint}\n" : '')
                . ($moodHint !== '' ? "- Mood: {$moodHint}\n" : '');
        }

        $systemPrompt = <<<PROMPT
You design concise image prompts for a Japanese AI art service.
Return JSON only and preserve the user's requested subject.

Create two English prompts:
- A: clear, friendly and reliable.
- B: slightly more imaginative, while keeping the same subject.

Rules:
- Describe one readable main subject, one coherent composition, lighting, palette and medium.
- Do not stuff the prompt with repetitive quality words.
- Do not use artist, studio or copyrighted character names.
- Do not request text, logos or watermarks in the image.
- Explicit exclusions are hard constraints. If the user says no people, persons, humans or characters, never introduce any person, child, face, portrait, human silhouette, fairy, angel, humanoid or anthropomorphic character. Interpret ambiguous winged sprouts as botanical seedlings with leaf-like wings, not as a person or creature.
- For people, children, families or human activities: prefer one person or at most two clearly separated people, medium or waist-up framing, a calm pose, a clearly visible pleasant face, and hands resting, partly hidden or outside the frame.
- For children, use a wholesome polished illustration rather than uncanny photorealism unless realism is explicitly requested.
- Avoid crowds, extreme perspective, tiny faces and complicated action poses.
- Keep each English prompt under 110 words.
{$contextNote}

Output schema:
{
  "input_summary_ja": "日本語で入力内容を要約",
  "input_type": "survey or simple_keywords or free_text",
  "prompt_a_title_ja": "A案の短い日本語タイトル",
  "prompt_a_en": "English prompt A",
  "prompt_b_title_ja": "B案の短い日本語タイトル",
  "prompt_b_en": "English prompt B",
  "safety_notes": ""
}
PROMPT;

        if ($this->provider === 'local') {
            return $this->normalizeResult([], $inputText);
        }

        try {
            $text = $this->requestOpenAI($systemPrompt, $inputText);
            if (preg_match('/\{.*\}/s', $text, $matches)) {
                $parsed = json_decode($matches[0], true);
                if (is_array($parsed)) {
                    return $this->normalizeResult($parsed, $inputText);
                }
            }
            throw new RuntimeException('プロンプト生成APIの応答を解析できませんでした。');
        } catch (Throwable $e) {
            $message = preg_replace('/\s+/u', ' ', trim($e->getMessage()));
            error_log(
                'PromptService: OpenAI prompt API unavailable; local fallback used. '
                . substr((string)$message, 0, 300)
            );
            return $this->normalizeResult([], $inputText);
        }
    }

    private function requestOpenAI(string $systemPrompt, string $inputText): string {
        $body = json_encode([
            'model' => $this->openaiModel,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $inputText],
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 1200,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiApiKey,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || $code !== 200) {
            $data = json_decode((string)$response, true);
            $message = trim((string)($data['error']['message'] ?? $error));
            throw new RuntimeException("OpenAI prompt API error: HTTP {$code} / {$message}");
        }

        $data = json_decode((string)$response, true);
        return (string)($data['choices'][0]['message']['content'] ?? '');
    }

    private function normalizeResult(array $data, string $inputText): array {
        $summary = trim((string)($data['input_summary_ja'] ?? $inputText));
        $promptA = trim((string)($data['prompt_a_en'] ?? ''));
        $promptB = trim((string)($data['prompt_b_en'] ?? ''));
        $titleA = trim((string)($data['prompt_a_title_ja'] ?? 'A案 - 明るく親しみやすい構図'));
        $titleB = trim((string)($data['prompt_b_title_ja'] ?? 'B案 - 雰囲気のある構図'));
        $noHuman = $this->requestsNoHumanSubject($inputText);

        if ($noHuman) {
            // A model may interpret "winged sprout" as a fairy. Replace its output
            // completely when the original Japanese request explicitly excludes people.
            $promptA = $this->noHumanPrompt($inputText, false);
            $promptB = $this->noHumanPrompt($inputText, true);
            $titleA = 'A案 - 羽の芽を近くで描く光の風景';
            $titleB = 'B案 - 虹の大地を広く見渡す幻想風景';
        } elseif ($promptA === '') {
            $promptA = $this->fallbackPrompt($inputText, false);
        } 
        if (!$noHuman && $promptB === '') {
            $promptB = $this->fallbackPrompt($inputText, true);
        }

        return [
            'input_summary_ja' => $summary,
            'input_type' => $data['input_type'] ?? $this->guessInputType($inputText),
            'prompt_a_title_ja' => $titleA,
            'prompt_a_en' => $this->finalizePrompt($promptA, $noHuman, 'A'),
            'prompt_b_title_ja' => $titleB,
            'prompt_b_en' => $this->finalizePrompt($promptB, $noHuman, 'B'),
            'safety_notes' => trim((string)($data['safety_notes'] ?? '')),
        ];
    }

    private function finalizePrompt(string $prompt, bool $noHuman = false, string $variant = ''): string {
        $prompt = trim(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt);

        if ($noHuman || $this->requestsNoHumanSubject($prompt)) {
            $guard = 'STRICT COMPOSITION: scenery and environment only. No humans, no people, no children, no faces, no portraits, no human silhouettes, no fairies, no angels, no humanoid or anthropomorphic characters. Any winged sprouts must be botanical seedlings with leaf-like wings, never a person or creature.';
            $variantLock = strtoupper($variant) === 'B'
                ? 'VARIANT B COMPOSITION LOCK: use a very wide elevated panoramic viewpoint over an expansive landscape. Do not use a macro view, close-up framing, or one dominant foreground seedling cluster.'
                : 'VARIANT A COMPOSITION LOCK: use an intimate ground-level viewpoint with a clearly defined foreground cluster of seedlings. Do not use an aerial view, panoramic valley, or distant mass of tiny sprouts.';
            $suffix = 'Photorealistic environmental scene, coherent green and blue palette, sparkling natural light, realistic materials, clean botanical detail, no text, no logo, no watermark.';
            return trim($guard . ' ' . $variantLock . ' ' . rtrim($prompt, " ,.") . '. ' . $suffix);
        }

        $isHuman = $this->isQualitySensitivePrompt($prompt);

        if ($isHuman) {
            $suffix = 'Polished illustration, one main person or at most two clearly separated people, medium framing, clearly visible pleasant face, calm natural pose, simple hands resting, partly hidden, or outside the frame, coherent anatomy, soft professional lighting, no text, no watermark.';
        } else {
            $suffix = 'Polished illustration, clear focal subject, balanced composition, refined colors, professional lighting, no text, no watermark.';
        }

        if (stripos($prompt, 'no watermark') === false) {
            $prompt = rtrim($prompt, " ,.") . '. ' . $suffix;
        }
        return trim($prompt);
    }

    private function isQualitySensitivePrompt(string $prompt): bool {
        if ($this->requestsNoHumanSubject($prompt)) {
            return false;
        }
        return $this->containsHumanSubject($prompt) || $this->containsHumanActivityContext($prompt);
    }

    private function containsHumanSubject(string $prompt): bool {
        if ($this->requestsNoHumanSubject($prompt)) {
            return false;
        }
        return $this->containsAny($prompt, [
            'person', 'people', 'human', 'girl', 'boy', 'child', 'children', 'kid', 'kids',
            'baby', 'woman', 'man', 'lady', 'family', 'portrait', 'face', 'smile',
            'mother', 'father', 'daughter', 'son', 'bride', 'student',
            '人物', '人', '女性', '男性', '女の子', '男の子', '子供', '子ども', '赤ちゃん',
            '家族', '顔', '笑顔', '母', '父', '娘', '息子', '少女', '少年',
        ]);
    }

    private function containsHumanActivityContext(string $prompt): bool {
        if ($this->requestsNoHumanSubject($prompt)) {
            return false;
        }
        return $this->containsAny($prompt, [
            'birthday', 'wedding', 'festival', 'party', 'dance', 'dancing', 'running',
            'swimming', 'playground', 'sports', 'vacation', 'school', 'classroom',
            '誕生日', '結婚式', '祭り', 'パーティー', '踊る', '走る', '泳ぐ',
            '遊ぶ', '運動', '旅行', '学校', '教室', '夏休み', 'バーベキュー',
        ]);
    }

    private function containsAny(string $text, array $needles): bool {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach ($needles as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function requestsNoHumanSubject(string $prompt): bool {
        return $this->containsAny($prompt, [
            '人物なし', '人物はなし', '人物を入れない', '人物を描かない', '人物を含めない', '人物不要', '人物のいない',
            '人なし', '人はなし', '人を入れない', '人を描かない', '人を含めない', '人のいない',
            '人間なし', '人間はなし', '誰もいない', '無人',
            'no people', 'no person', 'no persons', 'without people', 'without a person', 'without persons',
            'no human', 'no humans', 'without humans', 'human-free', 'no character', 'no characters',
            'without characters', 'uninhabited', 'empty landscape',
        ]);
    }

    private function noHumanPrompt(string $inputText, bool $dreamy): string {
        $request = preg_replace('/\s+/u', ' ', trim($inputText));
        $request = $request !== null && $request !== '' ? $request : trim($inputText);
        $request = str_replace(['"', '\\'], ["'", '/'], $request);

        $base = 'Create a scenery-only image matching this Japanese request: "'
            . $request . '". The scene must contain no person, child, face, portrait, human silhouette, fairy, angel, humanoid, character or anthropomorphic subject. Interpret small winged sprouts as botanical seedlings whose new leaves resemble tiny wings, emerging and fluttering in the breeze; they are plants, never people or creatures. ';

        if ($dreamy) {
            return $base
                . 'VARIANT B - GRAND PANORAMIC WORLD. Use a very wide elevated viewpoint looking over an expansive valley or wetland. Show dozens of tiny botanical winged seedlings fluttering across the middle distance, with a winding stream, luminous mist, and a full rainbow spanning the sky. Keep individual sprouts relatively small so the broad world and atmospheric depth are the main impression. Use green and blue tones, cinematic light, and photorealistic environmental detail. Never use a macro close-up or one dominant foreground seedling cluster.';
        }

        return $base
            . 'VARIANT A - INTIMATE BOTANICAL CLOSE VIEW. Use a low ground-level camera focused on one clearly arranged foreground cluster of newly emerging wing-like leaves in moss and dew. Make the seedlings and their delicate leaf texture large and readable, with soft sparkling morning light and only a distant, understated rainbow in the background. Use shallow-to-medium environmental depth, green and blue tones, and a beautiful photorealistic botanical finish. Never use an aerial viewpoint, a panoramic valley, or a distant flock of tiny sprouts.';
    }

    private function fallbackPrompt(string $inputText, bool $dreamy): string {
        $request = preg_replace('/\s+/u', ' ', trim($inputText));
        $request = $request !== null && $request !== '' ? $request : trim($inputText);
        $request = str_replace(['"', '\\'], ["'", '/'], $request);

        if ($this->requestsNoHumanSubject($request)) {
            return $this->noHumanPrompt($request, $dreamy);
        }

        $base = 'Create a polished illustration of this subject, described in Japanese: "'
            . $request . '". Preserve the requested meaning and make the subject immediately readable. ';

        if ($this->isQualitySensitivePrompt($request)) {
            $base .= 'Use one main person or at most two clearly separated people, medium framing, a pleasant readable face, a calm natural pose, and simple hands resting, partly hidden, or outside the frame. ';
        }

        if ($dreamy) {
            return $base . 'Use an imaginative but coherent setting, gentle atmosphere, refined color harmony, soft light, and a clean background.';
        }
        return $base . 'Use a friendly clear composition, natural light, refined colors, and a clean background.';
    }

    private function guessInputType(string $inputText): string {
        $length = function_exists('mb_strlen') ? mb_strlen(trim($inputText), 'UTF-8') : strlen(trim($inputText));
        return $length < 40 ? 'simple_keywords' : 'free_text';
    }
}
