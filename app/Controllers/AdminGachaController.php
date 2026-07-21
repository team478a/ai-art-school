<?php
require_once BASE_PATH . '/app/Services/GachaService.php';

class AdminGachaController {
    private GachaService $service;

    public function __construct() {
        $this->service = new GachaService();
    }

    public function index(): void {
        $summary = $this->service->adminSummary();
        $schedules = $this->service->schedulesForGrant();
        $results = $this->service->recentResults();
        $interests = $this->service->recentInterests();
        $message = $_GET['message'] ?? '';
        $error = $_GET['error'] ?? '';
        require BASE_PATH . '/app/Views/admin/gacha.php';
    }

    public function settings(): void {
        $summary = $this->service->adminSummary();
        $config = $this->service->adminConfig();
        $message = $_GET['message'] ?? '';
        $error = $_GET['error'] ?? '';
        require BASE_PATH . '/app/Views/admin/gacha_settings.php';
    }

    public function saveCampaign(): void {
        $this->verifyCsrf();
        $this->service->saveCampaignSettings($_POST);
        $this->redirectWithMessage('/admin/gacha-settings', 'ガチャ基本設定を保存しました。');
    }

    public function saveRarities(): void {
        $this->verifyCsrf();
        $this->service->saveRaritySettings($_POST);
        $this->redirectWithMessage('/admin/gacha-settings', 'レア度と抽選比率を保存しました。');
    }

    public function savePrizes(): void {
        $this->verifyCsrf();
        $this->service->savePrizeSettings($_POST);
        $this->redirectWithMessage('/admin/gacha-settings', '景品設定を保存しました。');
    }

    public function grant(int $scheduleId): void {
        $this->verifyCsrf();
        $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        $result = $this->service->grantForSchedule($scheduleId, $adminId);
        $message = '参加権を付与しました。対象' . (int)$result['eligible'] . '人 / 新規' . (int)$result['created'] . '件';
        $this->redirectWithMessage('/admin/gacha', $message);
    }

    public function notify(int $scheduleId): void {
        $this->verifyCsrf();
        $result = $this->service->notifyForSchedule($scheduleId);
        $message = 'LINE案内を送信しました。対象' . (int)$result['target'] . '人 / 送信' . (int)$result['sent'] . '件';
        $this->redirectWithMessage('/admin/gacha', $message);
    }

    private function verifyCsrf(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
    }

    private function redirectWithMessage(string $path, string $message): void {
        header('Location: ' . $path . '?message=' . urlencode($message));
        exit;
    }
}
