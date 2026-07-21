<?php

require_once BASE_PATH . '/app/Services/RichMenuSegmentService.php';

class AdminRichMenuSegmentController {
    private RichMenuSegmentService $service;

    public function __construct() {
        $this->service = new RichMenuSegmentService();
    }

    public function show(): void {
        $pageTitle = 'リッチメニュー設定';
        $config = $this->service->getConfig();
        $labels = $this->service->segmentLabels();
        $priorityLabels = $this->service->priorityLabels();
        $presetOptions = $this->service->buttonPresetOptions();
        $presetDefinitions = $this->service->buttonPresetDefinitions();
        $saved = isset($_GET['saved']);
        $synced = isset($_GET['synced']);
        $onlineMode = isset($_GET['online_mode']);
        $defaultCreated = isset($_GET['default_created']);
        $created = trim((string)($_GET['created'] ?? ''));
        $createdId = trim((string)($_GET['id'] ?? ''));
        $error = trim((string)($_GET['error'] ?? ''));
        require BASE_PATH . '/app/Views/admin/richmenu_segments.php';
    }

    public function save(): void {
        $this->verifyCsrf();
        try {
            $this->service->saveConfig($_POST);
            header('Location: /admin/richmenu-segments?saved=1');
            exit;
        } catch (Throwable $e) {
            header('Location: /admin/richmenu-segments?error=' . rawurlencode($e->getMessage()));
            exit;
        }
    }

    public function create(): void {
        $this->verifyCsrf();
        $segment = preg_replace('/[^a-z_]/', '', (string)($_POST['segment'] ?? ''));
        try {
            $richMenuId = $this->service->createSegmentRichMenu($segment);
            header('Location: /admin/richmenu-segments?created=' . rawurlencode($segment) . '&id=' . rawurlencode($richMenuId));
            exit;
        } catch (Throwable $e) {
            header('Location: /admin/richmenu-segments?error=' . rawurlencode($e->getMessage()));
            exit;
        }
    }

    public function applyOnlineGeneration(): void {
        $this->verifyCsrf();
        try {
            $this->service->applyOnlineGenerationOnlyTemplate();
            header('Location: /admin/richmenu-segments?online_mode=1');
            exit;
        } catch (Throwable $e) {
            header('Location: /admin/richmenu-segments?error=' . rawurlencode($e->getMessage()));
            exit;
        }
    }

    public function createOnlineDefault(): void {
        $this->verifyCsrf();
        try {
            $richMenuId = $this->service->createOnlineDefaultRichMenu();
            header('Location: /admin/richmenu-segments?default_created=1&id=' . rawurlencode($richMenuId));
            exit;
        } catch (Throwable $e) {
            header('Location: /admin/richmenu-segments?error=' . rawurlencode($e->getMessage()));
            exit;
        }
    }

    public function sync(): void {
        $this->verifyCsrf();
        try {
            $limit = (int)($_POST['limit'] ?? 500);
            $result = $this->service->syncAll($limit);
            header('Location: /admin/richmenu-segments?synced=1&ok=' . (int)$result['success'] . '&ng=' . (int)$result['failed']);
            exit;
        } catch (Throwable $e) {
            header('Location: /admin/richmenu-segments?error=' . rawurlencode($e->getMessage()));
            exit;
        }
    }

    private function verifyCsrf(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
    }
}
