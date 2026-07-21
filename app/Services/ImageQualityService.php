<?php

require_once BASE_PATH . '/config/settings.php';

class ImageQualityService
{
    public function inspect(array $image, array $existingFingerprints = [], array $context = []): array
    {
        $data = (string)($image['data'] ?? '');
        $reasons = [];
        $warnings = [];
        $metrics = ['bytes' => strlen($data), 'width' => 0, 'height' => 0];
        $fingerprint = ['sha256' => '', 'dhash' => ''];

        if ($data === '') {
            $reasons[] = 'Image data is empty.';
            return $this->result(false, $reasons, $warnings, $metrics, $fingerprint);
        }

        $fingerprint['sha256'] = hash('sha256', $data);
        $meta = @getimagesizefromstring($data);
        if ($meta === false) {
            $reasons[] = 'The generated data is not a readable image.';
            return $this->result(false, $reasons, $warnings, $metrics, $fingerprint);
        }

        $metrics['width'] = (int)($meta[0] ?? 0);
        $metrics['height'] = (int)($meta[1] ?? 0);
        $metrics['mime'] = (string)($meta['mime'] ?? 'image/png');

        if (!$this->enabled('image_quality_gate_enabled', true)) {
            return $this->result(true, [], [], $metrics, $fingerprint);
        }

        $minWidth = max(64, (int)Settings::get('image_quality_min_width', 512));
        $minHeight = max(64, (int)Settings::get('image_quality_min_height', 512));
        if ($metrics['width'] < $minWidth || $metrics['height'] < $minHeight) {
            $reasons[] = 'Image dimensions are below the configured minimum.';
        }
        if ($metrics['bytes'] < 4096) {
            $reasons[] = 'Image data is unexpectedly small.';
        }

        $fingerprint['dhash'] = $this->differenceHash($data);
        $threshold = max(0, min(24, (int)Settings::get('image_quality_duplicate_distance', 6)));
        foreach ($existingFingerprints as $known) {
            if (!is_array($known)) {
                continue;
            }
            $knownSha = (string)($known['sha256'] ?? '');
            if ($knownSha !== '' && hash_equals($knownSha, $fingerprint['sha256'])) {
                $reasons[] = 'An identical image was generated.';
                break;
            }
            $knownHash = (string)($known['dhash'] ?? '');
            if ($fingerprint['dhash'] !== '' && $knownHash !== ''
                && $this->hammingDistance($fingerprint['dhash'], $knownHash) <= $threshold) {
                $reasons[] = 'A visually similar image was generated.';
                break;
            }
        }

        if ($this->enabled('image_quality_vision_check_enabled', false)) {
            $vision = $this->visionInspection($data, (string)$metrics['mime'], $context);
            $warnings = array_merge($warnings, $vision['warnings']);
            $metrics['vision'] = $vision['metrics'];
            $reasons = array_merge($reasons, $vision['reasons']);
        }

        return $this->result(
            count($reasons) === 0,
            array_values(array_unique($reasons)),
            array_values(array_unique($warnings)),
            $metrics,
            $fingerprint
        );
    }

    private function enabled(string $key, bool $default): bool
    {
        $value = Settings::get($key, $default ? '1' : '0');
        return !in_array(strtolower(trim((string)$value)), ['', '0', 'false', 'off', 'no'], true);
    }

    private function result(bool $accepted, array $reasons, array $warnings, array $metrics, array $fingerprint): array
    {
        return [
            'accepted' => $accepted,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'metrics' => $metrics,
            'fingerprint' => $fingerprint,
        ];
    }

    private function differenceHash(string $data): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return '';
        }

        $source = @imagecreatefromstring($data);
        if ($source === false) {
            return '';
        }

        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = imagecolorat($small, $x, $y);
                $right = imagecolorat($small, $x + 1, $y);
                $bits .= $this->luminance($left) > $this->luminance($right) ? '1' : '0';
            }
        }
        imagedestroy($small);
        imagedestroy($source);

        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }
        return $hex;
    }

    private function luminance(int $color): float
    {
        $r = ($color >> 16) & 0xff;
        $g = ($color >> 8) & 0xff;
        $b = $color & 0xff;
        return ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
    }

    private function hammingDistance(string $a, string $b): int
    {
        if ($a === '' || strlen($a) !== strlen($b) || !ctype_xdigit($a) || !ctype_xdigit($b)) {
            return PHP_INT_MAX;
        }
        $lookup = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
        $distance = 0;
        for ($i = 0, $length = strlen($a); $i < $length; $i++) {
            $distance += $lookup[hexdec($a[$i]) ^ hexdec($b[$i])];
        }
        return $distance;
    }

    private function visionInspection(string $data, string $mime, array $context): array
    {
        $result = ['reasons' => [], 'warnings' => [], 'metrics' => []];
        $apiKey = trim((string)Settings::get('openai_api_key', ''));
        if ($apiKey === '' || !function_exists('curl_init')) {
            $result['warnings'][] = 'Vision quality inspection was skipped because OpenAI or cURL is unavailable.';
            return $result;
        }

        $input = (string)($context['input_text'] ?? '');
        $noPeople = preg_match('/(?:人物なし|人物はなし|人物を入れない|人物を描かない|人なし|人はいらない|人を入れない|人間なし|no people|without people|no person)/iu', $input) === 1;
        $instruction = 'Inspect the image. Return JSON only with boolean has_person and numeric face_quality, hand_quality, anatomy_quality from 0 to 1. Penalize malformed faces, extra or missing fingers, extra limbs and broken anatomy.';
        $payload = [
            'model' => (string)Settings::get('image_quality_vision_model', 'gpt-4o-mini'),
            'temperature' => 0,
            'max_tokens' => 180,
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $instruction],
                    ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:' . $mime . ';base64,' . base64_encode($data),
                        'detail' => 'low',
                    ]],
                ],
            ]],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            $result['warnings'][] = 'Vision quality inspection failed (HTTP ' . $status . ($error !== '' ? ': ' . $error : '') . ').';
            return $result;
        }

        $response = json_decode((string)$body, true);
        $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        $metrics = json_decode($content, true);
        if (!is_array($metrics)) {
            $result['warnings'][] = 'Vision quality inspection returned invalid JSON.';
            return $result;
        }
        $result['metrics'] = $metrics;

        $minimum = max(0.1, min(1.0, (float)Settings::get('image_quality_vision_min_score', 0.55)));
        foreach (['face_quality' => 'Face', 'hand_quality' => 'Hand', 'anatomy_quality' => 'Anatomy'] as $key => $label) {
            if (isset($metrics[$key]) && is_numeric($metrics[$key]) && (float)$metrics[$key] < $minimum) {
                $result['reasons'][] = $label . ' quality is below the configured minimum.';
            }
        }
        if ($noPeople && $this->enabled('image_quality_reject_people_when_forbidden', true)
            && filter_var($metrics['has_person'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $result['reasons'][] = 'A person was detected even though the request forbids people.';
        }

        return $result;
    }
}
