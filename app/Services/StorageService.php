<?php
require_once BASE_PATH . '/config/settings.php';

class StorageService {
    public function save(string $binary, string $relativePath): string {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (strpos($relativePath, '..') !== false) {
            throw new \RuntimeException('Invalid storage path');
        }

        [$binary, $relativePath] = $this->preparePublicImageForLine($binary, $relativePath);

        $baseDir = defined('STORAGE_PATH') ? STORAGE_PATH : BASE_PATH . '/storage';
        $publicDir = BASE_PATH . '/uploads';
        $target = $publicDir . '/' . $relativePath;
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('保存先フォルダを作成できません: ' . $dir);
        }
        if (file_put_contents($target, $binary) === false) {
            throw new \RuntimeException('ファイル保存に失敗しました: ' . $relativePath);
        }

        $configured = rtrim(Settings::storagePublicUrl(), '/');
        if ($configured !== '') {
            return $configured . '/' . $relativePath;
        }

        $scheme = 'https';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            return $scheme . '://' . $host . '/uploads/' . $relativePath;
        }
        return '/uploads/' . $relativePath;
    }

    private function preparePublicImageForLine(string $binary, string $relativePath): array {
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            return [$binary, $relativePath];
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return [$binary, $relativePath];
        }

        $source = @imagecreatefromstring($binary);
        if (!$source) {
            return [$binary, $relativePath];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return [$binary, $relativePath];
        }

        $maxSide = (int)(class_exists('Settings') ? Settings::get('line_image_max_side', '1024') : 1024);
        $maxSide = max(640, min(2048, $maxSide));
        $scale = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$canvas) {
            imagedestroy($source);
            return [$binary, $relativePath];
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $quality = (int)(class_exists('Settings') ? Settings::get('line_image_jpeg_quality', '88') : 88);
        $quality = max(70, min(95, $quality));
        ob_start();
        imagejpeg($canvas, null, $quality);
        $jpeg = (string)ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        if ($jpeg === '') {
            return [$binary, $relativePath];
        }

        $relativePath = preg_replace('/\.(png|jpe?g|webp)$/i', '.jpg', $relativePath);
        if (!is_string($relativePath) || $relativePath === '') {
            $relativePath .= '.jpg';
        }

        return [$jpeg, $relativePath];
    }

    public function savePrivate(string $binary, string $relativePath): string {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (strpos($relativePath, '..') !== false) {
            throw new \RuntimeException('Invalid storage path');
        }
        $baseDir = defined('STORAGE_PATH') ? STORAGE_PATH : BASE_PATH . '/storage';
        $target = $baseDir . '/' . $relativePath;
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('保存先フォルダを作成できません: ' . $dir);
        }
        if (file_put_contents($target, $binary) === false) {
            throw new \RuntimeException('ファイル保存に失敗しました: ' . $relativePath);
        }
        return $target;
    }
}
