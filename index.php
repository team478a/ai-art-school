<?php
// index.php 遯ｶ繝ｻ郢晁ｼ釆溽ｹ晢ｽｳ郢晏現縺慕ｹ晢ｽｳ郢晏現ﾎ溽ｹ晢ｽｼ郢晢ｽｩ郢晢ｽｼ
// 髫ｪ・ｭ驗ゑｽｮ陜｣・ｴ隰・: public_html/a-iart.sengoku-ai.com/index.php

// BASE_PATH = index.php 邵ｺ蠕娯旺郢ｧ荵昴Ι郢ｧ・｣郢晢ｽｬ郢ｧ・ｯ郢晏現ﾎ憺明・ｪ髴・ｽｫ
define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/config/app.php';

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if (!function_exists('aiart_require_line_config_owner')) {
    function aiart_require_line_config_owner(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (empty($_SESSION['admin_id'])) {
            header('Location: /admin/login');
            exit;
        }

        $role = (string)($_SESSION['admin_role'] ?? '');
        $isOwner = in_array($role, ['super_owner', 'owner', 'Owner', 'OWNER', 'オーナー'], true)
            || !empty($_SESSION['is_owner'])
            || !empty($_SESSION['admin_is_owner']);

        if (class_exists('AdminAuthController') && method_exists('AdminAuthController', 'isOwner')) {
            $isOwner = $isOwner || AdminAuthController::isOwner();
        }

        if (!$isOwner) {
            header('Location: /admin/dashboard');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf')) {
            verify_csrf();
        }
    }
}

if (!function_exists('aiart_require_owner_for_api_test')) {
    function aiart_require_owner_for_api_test(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        header('Content-Type: application/json; charset=UTF-8');

        if (empty($_SESSION['admin_id'])) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'message' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $role = (string)($_SESSION['admin_role'] ?? '');
        $isOwner = in_array($role, ['super_owner', 'owner', 'Owner', 'OWNER', 'オーナー', '繧ｪ繝ｼ繝翫・'], true)
            || !empty($_SESSION['is_owner'])
            || !empty($_SESSION['admin_is_owner']);

        if (!$isOwner) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => '接続テストはオーナーのみ実行できます。'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// 郢ｧ・､郢晢ｽｳ郢ｧ・ｹ郢晏現繝ｻ郢晢ｽｫ隴幢ｽｪ陞ｳ蠕｡・ｺ繝ｻ繝ｻ郢ｧ・､郢晢ｽｳ郢ｧ・ｹ郢晏現繝ｻ郢晢ｽｩ郢晢ｽｼ邵ｺ・ｸ
if (!function_exists('aiart_public_tenant_guard_context')) {
    function aiart_public_tenant_guard_context(string $path, string $method): ?string {
        if (in_array($path, ['/', '/terms', '/privacy', '/legal', '/commercial-transactions', '/tokushoho'], true)) {
            return 'page';
        }
        if ($path === '/webhook/line' || preg_match('#^/webhook/line/[A-Za-z0-9_-]+$#', $path)) {
            return 'webhook';
        }
        if ($path === '/stripe/webhook' || preg_match('#^/stripe/webhook/[A-Za-z0-9_-]+$#', $path)) {
            return $method === 'POST' ? 'webhook' : 'page';
        }
        if ($path === '/shopping/webhook' || preg_match('#^/shopping/webhook/[A-Za-z0-9_-]+$#', $path)) {
            return $method === 'POST' ? 'webhook' : 'page';
        }
        if ($path === '/liff' || strpos($path, '/liff/') === 0) {
            return $method === 'POST' ? 'json' : 'liff';
        }
        return null;
    }
}

if (!function_exists('aiart_guard_public_tenant_or_exit')) {
    function aiart_guard_public_tenant_or_exit(string $context = 'page'): void {
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Services/TenantAccessGuardService.php';
        TenantAccessGuardService::blockIfSuspended($context);
    }
}

if (!INSTALLED && $path !== '/install' && !preg_match('#^/install\.php#', $path)) {
    header('Location: /install');
    exit;
}

$tenantGuardContext = aiart_public_tenant_guard_context($path, $method);
if ($tenantGuardContext !== null) {
    aiart_guard_public_tenant_or_exit($tenantGuardContext);
}

switch (true) {

    case in_array($path, ['/', '/terms', '/privacy', '/legal', '/commercial-transactions', '/tokushoho'], true):
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/PublicPageController.php';
        (new PublicPageController())->show($path);
        break;

    case $path === '/liff/paid':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffPaidController.php';
        (new LiffPaidController())->show();
        break;

    // LINE Webhook
    case ($path === '/webhook/line' || preg_match('#^/webhook/line/[A-Za-z0-9_-]+$#', $path)) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/SurveyDefinition.php';
        require_once BASE_PATH . '/app/Services/UserSessionService.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Controllers/LineWebhookController.php';
        (new LineWebhookController())->handle();
        break;

    // Admin: 郢晢ｽｭ郢ｧ・ｰ郢ｧ・､郢晢ｽｳ
    case $path === '/admin/login':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        $ctrl = new AdminAuthController();
        $method === 'POST' ? $ctrl->login() : $ctrl->showLogin();
        break;

    // Admin: 郢晢ｽｭ郢ｧ・ｰ郢ｧ・｢郢ｧ・ｦ郢昴・
    case $path === '/admin/logout':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        (new AdminAuthController())->logout();
        break;

    // Admin: 郢敖郢昴・縺咏ｹ晢ｽ･郢晄㈱繝ｻ郢昴・
    case $path === '/admin' || $path === '/admin/' || $path === '/admin/dashboard':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminImageRequestController.php';
        AdminAuthController::requireLogin();
        (new AdminImageRequestController())->dashboard();
        break;

    // Admin: 關捺辨・ｰ・ｼ闕ｳﾂ髫包ｽｧ
    case $path === '/admin/image-requests' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminImageRequestController.php';
        AdminAuthController::requireLogin();
        (new AdminImageRequestController())->index();
        break;

    // Admin: 關捺辨・ｰ・ｼ髫ｧ・ｳ驍擾ｽｰ
    case preg_match('#^/admin/image-requests/(\d+)$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminImageRequestController.php';
        AdminAuthController::requireLogin();
        (new AdminImageRequestController())->show((int)$m[1]);
        break;

    // Admin: 陷蜥ｲ蜃ｽ隰後・
    case preg_match('#^/admin/image-requests/(\d+)/retry$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminImageRequestController.php';
        AdminAuthController::requireLogin();
        (new AdminImageRequestController())->retry((int)$m[1]);
        break;

    // Admin: process one received image request immediately
    case preg_match('#^/admin/image-requests/(\d+)/process-now$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminImageRequestController.php';
        AdminAuthController::requireAdmin();
        (new AdminImageRequestController())->processNow((int)$m[1]);
        break;

    // Admin: run one image job and the scheduled notification tasks manually
    case $path === '/admin/manual-cron' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminImageRequestController.php';
        AdminAuthController::requireAdmin();
        (new AdminImageRequestController())->processNext();
        break;

    // Admin: resend already generated images to LINE
    case preg_match('#^/admin/image-requests/(\d+)/resend$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminImageRequestController.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        AdminAuthController::requireLogin();
        (new AdminImageRequestController())->resend((int)$m[1]);
        break;

    // Admin: 髫ｪ・ｭ陞ｳ螟ｲ・ｼ蛹ｻ縺檎ｹ晢ｽｼ郢晉ｿｫ繝ｻ陝・ｉ逡代・繝ｻ
    case $path === '/admin/settings':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        $ctrl = new AdminSettingsController();
        $method === 'POST' ? $ctrl->save() : $ctrl->show();
        break;

    case $path === '/admin/public-settings':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminPublicSettingsController.php';
        AdminAuthController::requireOwner();
        $ctrl = new AdminPublicSettingsController();
        $method === 'POST' ? $ctrl->save() : $ctrl->show();
        break;

    case $path === '/admin/client-setup':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        $ctrl = new AdminSettingsController();
        $method === 'POST' ? $ctrl->saveClientSetup() : $ctrl->clientSetup();
        break;

    case $path === '/admin/tenants' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->index();
        break;

    case $path === '/admin/tenants/monthly-report' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->monthlyReport();
        break;

    case $path === '/admin/tenants/create' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->create();
        break;

    case $path === '/admin/tenants' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->store();
        break;

    case preg_match('#^/admin/tenants/(\d+)/edit$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->edit((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/settings$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->settings((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/diagnostics$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->diagnostics((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/diagnostics/recover$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->recoverDiagnostics((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/backups$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->backups((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/backups$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->createBackup((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/backups/([A-Za-z0-9_-]+)/download$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->downloadBackup((int)$m[1], (string)$m[2]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/backups/restore$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->restoreBackup((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/handover$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->exportHandover((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/settings$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->saveSettings((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/richmenu-sync$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->syncRichMenu((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->update((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/status$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->status((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/make-default$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->makeDefault((int)$m[1]);
        break;

    case preg_match('#^/admin/tenants/(\d+)/switch$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->switchTenant((int)$m[1]);
        break;

    case $path === '/admin/tenants/clear-switch' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTenantController.php';
        AdminAuthController::requireOwner();
        (new AdminTenantController())->clearTenantSwitch();
        break;

    case $path === '/admin/client-setup/defaults' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->applyClientSetupDefaults();
        break;

    case $path === '/admin/client-setup/wizard' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->applyClientWizard();
        break;

    case $path === '/admin/client-setup/template' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->applyClientTemplate();
        break;

    case $path === '/admin/client-setup/preflight' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->runClientPreflight();
        break;

    case $path === '/admin/client-setup/export' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->exportClientSetup();
        break;

    case $path === '/admin/client-setup/checklist' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->exportClientChecklist();
        break;

    case $path === '/admin/client-setup/handover' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->exportClientHandover();
        break;

    case $path === '/admin/client-setup/guide' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->exportClientGuide();
        break;

    case $path === '/admin/client-setup/import' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->importClientSetup();
        break;

    case $path === '/admin/client-setup/snapshot' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->createClientSetupSnapshot();
        break;

    case $path === '/admin/client-setup/restore' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->restoreClientSetupSnapshot();
        break;

    case $path === '/admin/client-setup/restore-partial' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireOwner();
        (new AdminSettingsController())->restoreClientSetupPartial();
        break;

    // Admin: Stability郢ｧ・ｯ郢晢ｽｬ郢ｧ・ｸ郢昴・繝ｨ隴厄ｽｴ隴・ｽｰ繝ｻ蛹ｻ繝郢昴・縺咏ｹ晢ｽ･郢晄㈱繝ｻ郢晁・逡代・繝ｻ
    case $path === '/admin/stability-credits' && in_array($method, ['GET', 'POST'], true):
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        AdminAuthController::requireLogin();
        (new AdminSettingsController())->refreshStabilityCredits();
        break;

    // Admin: API隰暦ｽ･驍ｯ螢ｹ繝ｦ郢ｧ・ｹ郢晁肩・ｼ蛹ｻ縺檎ｹ晢ｽｼ郢晉ｿｫ繝ｻ陝・ｉ逡代・繝ｻ
    case $path === '/admin/settings/test' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminSettingsController.php';
        aiart_require_owner_for_api_test();
        (new AdminSettingsController())->testApi();
        break;

    // Admin: ZIP郢ｧ・｢郢昴・繝ｻ郢晢ｽｭ郢晢ｽｼ郢晏ｳｨ縺・ｹ昴・繝ｻ郢昴・繝ｻ郢晁肩・ｼ蛹ｻ縺檎ｹ晢ｽｼ郢晉ｿｫ繝ｻ陝・ｉ逡代・繝ｻ
    case $path === '/admin/update/upload' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUpdateController.php';
        AdminAuthController::requireOwner();
        (new AdminUpdateController())->upload();
        break;

    case $path === '/admin/update/rollback' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUpdateController.php';
        AdminAuthController::requireOwner();
        (new AdminUpdateController())->rollback();
        break;

    case $path === '/admin/update/restore' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUpdateController.php';
        AdminAuthController::requireOwner();
        (new AdminUpdateController())->restore();
        break;

    // Admin: 郢ｧ・｢郢昴・繝ｻ郢昴・繝ｻ郢晁肩・ｼ蛹ｻ縺檎ｹ晢ｽｼ郢晉ｿｫ繝ｻ陝・ｉ逡代・繝ｻ
    case $path === '/admin/update':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUpdateController.php';
        AdminAuthController::requireOwner();
        (new AdminUpdateController())->show();
        break;

    // Admin: 郢ｧ・ｹ郢ｧ・ｱ郢ｧ・ｸ郢晢ｽ･郢晢ｽｼ郢晢ｽｫ闕ｳﾂ髫包ｽｧ
    case $path === '/admin/classes' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->index();
        break;

    // Admin: 郢ｧ・ｹ郢ｧ・ｱ郢ｧ・ｸ郢晢ｽ･郢晢ｽｼ郢晢ｽｫ闖ｴ諛医・
    case $path === '/admin/classes/create' || ($path === '/admin/classes' && $method === 'POST'):
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        $ctrl = new AdminClassController();
        $method === 'POST' ? $ctrl->store() : $ctrl->create();
        break;
    // Admin: class schedule detail view
    case preg_match('#^/admin/classes/(\d+)$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassDetailController.php';
        AdminAuthController::requireLogin();
        (new AdminClassDetailController())->show((int)$m[1]);
        break;

    // Admin: class schedule message send
    case preg_match('#^/admin/classes/(\d+)/message$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassDetailController.php';
        AdminAuthController::requireLogin();
        (new AdminClassDetailController())->sendMessage((int)$m[1]);
        break;

    // Admin: promote waitlist entry to an approved reservation
    case preg_match('#^/admin/classes/waitlist/(\d+)/promote$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassDetailController.php';
        AdminAuthController::requireLogin();
        (new AdminClassDetailController())->promoteWaitlist((int)$m[1]);
        break;

    // Admin: cancel a waitlist entry
    case preg_match('#^/admin/classes/waitlist/(\d+)/delete$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassDetailController.php';
        AdminAuthController::requireLogin();
        (new AdminClassDetailController())->deleteWaitlist((int)$m[1]);
        break;

    // Admin: 郢ｧ・ｹ郢ｧ・ｱ郢ｧ・ｸ郢晢ｽ･郢晢ｽｼ郢晢ｽｫ驍ｱ・ｨ鬮ｮ繝ｻ
    case preg_match('#^/admin/classes/(\d+)/edit$#', $path, $m):
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        $ctrl = new AdminClassController();
        $method === 'POST' ? $ctrl->update((int)$m[1]) : $ctrl->edit((int)$m[1]);
        break;

    // Admin: 郢ｧ・ｹ郢ｧ・ｱ郢ｧ・ｸ郢晢ｽ･郢晢ｽｼ郢晢ｽｫ隴厄ｽｴ隴・ｽｰ
    case preg_match('#^/admin/classes/(\d+)/update$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->update((int)$m[1]);
        break;

    // Admin: 郢ｧ・ｹ郢ｧ・ｱ郢ｧ・ｸ郢晢ｽ･郢晢ｽｼ郢晢ｽｫ郢ｧ・ｭ郢晢ｽ｣郢晢ｽｳ郢ｧ・ｻ郢晢ｽｫ
    case preg_match('#^/admin/classes/(\d+)/cancel$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->cancel((int)$m[1]);
        break;

    // Admin: class schedule delete
    case preg_match('#^/admin/classes/(\d+)/delete$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->deleteSchedule((int)$m[1]);
        break;

    // Admin: 陷ｿ繧・・隰・ｽｿ髫ｱ繝ｻ
    case preg_match('#^/admin/classes/attendance/(\d+)/approve$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->approve((int)$m[1]);
        break;

    // Admin: 鬮ｮ繝ｻ竕｡雋ょ現竏ｩ邵ｺ・ｫ邵ｺ蜷ｶ・・    case preg_match('#^/admin/classes/attendance/(\d+)/paid$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->markPaid((int)$m[1]);
        break;

    // Admin: 陷ｿ繧・・陷奇ｽｴ闕ｳ繝ｻ
    case preg_match('#^/admin/classes/attendance/(\d+)/reject$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->reject((int)$m[1]);
        break;

    // Admin: attendance delete
    case preg_match('#^/admin/classes/attendance/(\d+)/delete$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->deleteAttendance((int)$m[1]);
        break;

    // Admin: 陷茨ｽｨ陷ｩ・｡隰・ｽｿ髫ｱ繝ｻ
    case preg_match('#^/admin/classes/(\d+)/approve-all$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->approveAll((int)$m[1]);
        break;

    // Admin: approve selected reservations
    case preg_match('#^/admin/classes/(\d+)/approve-selected$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->approveSelected((int)$m[1]);
        break;

    // Admin: 闕ｳﾂ隴∝ｳｨﾎ鍋ｹ昴・縺晉ｹ晢ｽｼ郢ｧ・ｸ
    case $path === '/admin/broadcast':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminBroadcastController.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        AdminAuthController::requireLogin();
        $ctrl = new AdminBroadcastController();
        $method === 'POST' ? $ctrl->send() : $ctrl->show();
        break;

    // Admin: 陷・ｽｺ陝ｶ・ｭ陞ｻ・･雎・ｽｴ
    case $path === '/admin/attendance':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminAttendanceController.php';
        AdminAuthController::requireLogin();
        (new AdminAttendanceController())->index();
        break;

    // Admin: 郢晢ｽｦ郢晢ｽｼ郢ｧ・ｶ郢晢ｽｼ闕ｳﾂ髫包ｽｧ
    case $path === '/admin/users' && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->index();
        break;

    // Admin: 郢晢ｽｦ郢晢ｽｼ郢ｧ・ｶ郢晢ｽｼ髫ｧ・ｳ驍擾ｽｰ
    case preg_match('#^/admin/users/(\d+)$#', $path, $m) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->show((int)$m[1]);
        break;

    // Admin: 郢晢ｽｦ郢晢ｽｼ郢ｧ・ｶ郢晢ｽｼ郢ｧ・ｹ郢昴・繝ｻ郢ｧ・ｿ郢ｧ・ｹ陞溽判蟲ｩ
    case preg_match('#^/admin/users/(\d+)/status$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->updateStatus((int)$m[1]);
        break;

    // Admin: 闔ｨ螢ｼ阯､陋ｹ・ｺ陋ｻ繝ｻ・､逕ｻ蟲ｩ
    case preg_match('#^/admin/users/(\d+)/member-type$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->setMemberType((int)$m[1]);
        break;

    // Admin: 郢昶・縺鍋ｹ昴・繝ｨ闔牙・ｽｸ繝ｻ
    case preg_match('#^/admin/users/(\d+)/tickets$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->addTickets((int)$m[1]);
        break;

    // Admin: adjust today's image generation count
    case preg_match('#^/admin/users/(\d+)/generation-usage$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->setGenerationUsage((int)$m[1]);
        break;

    // Admin: grant a tenant-scoped user generation test access
    case preg_match('#^/admin/users/(\d+)/generation-test$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->setGenerationTestMode((int)$m[1]);
        break;

    // Admin: 郢晢ｽｦ郢晢ｽｼ郢ｧ・ｶ郢晢ｽｼ郢晢ｽ｡郢晢ｽ｢
    case preg_match('#^/admin/users/(\d+)/memo$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->updateMemo((int)$m[1]);
        break;

    // Admin: 郢晢ｽｦ郢晢ｽｼ郢ｧ・ｶ郢晢ｽｼ郢晢ｽ｡郢昴・縺晉ｹ晢ｽｼ郢ｧ・ｸ鬨ｾ竏ｽ・ｿ・｡
    case preg_match('#^/admin/users/(\d+)/message$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminUserController.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminUserController())->sendMessage((int)$m[1]);
        break;

    // Admin: 闔臥ｿｫ笘・ｸｺ闊湖懃ｹ晄ｧｭ縺・ｹ晢ｽｳ郢敖郢晢ｽｼ鬨ｾ竏ｽ・ｿ・｡
    case preg_match('#^/admin/classes/(\d+)/remind$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminClassController.php';
        require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        AdminAuthController::requireLogin();
        (new AdminClassController())->sendReminder((int)$m[1]);
        break;

    // Admin: 驍ゑｽ｡騾・・ﾂ繝ｻ・ｮ・｡騾・・・ｼ蛹ｻ縺檎ｹ晢ｽｼ郢晉ｿｫ繝ｻ陝・ｉ逡代・繝ｻ
    case $path === '/admin/managers':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminManagerController.php';
        AdminAuthController::requireOwner();
        $ctrl = new AdminManagerController();
        $method === 'POST' ? $ctrl->store() : $ctrl->index();
        break;

    case preg_match('#^/admin/managers/(\d+)/role$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminManagerController.php';
        AdminAuthController::requireOwner();
        (new AdminManagerController())->updateRole((int)$m[1]);
        break;

    case preg_match('#^/admin/managers/(\d+)/tenant$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminManagerController.php';
        AdminAuthController::requireOwner();
        (new AdminManagerController())->updateTenant((int)$m[1]);
        break;

    case preg_match('#^/admin/managers/(\d+)/status$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminManagerController.php';
        AdminAuthController::requireOwner();
        (new AdminManagerController())->updateStatus((int)$m[1]);
        break;

    case preg_match('#^/admin/managers/(\d+)/password$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminManagerController.php';
        AdminAuthController::requireOwner();
        (new AdminManagerController())->resetPassword((int)$m[1]);
        break;

    case preg_match('#^/admin/managers/(\d+)/delete$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminManagerController.php';
        AdminAuthController::requireOwner();
        (new AdminManagerController())->delete((int)$m[1]);
        break;

    // Admin: LINE設定
    case in_array($path, ['/admin/line-config', '/admin/line_config', '/admin/line-settings', '/admin/line-settings/'], true):
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->show();
        break;

    case in_array($path, ['/admin/line-config/greeting', '/admin/line_config/greeting', '/admin/line-settings/greeting'], true) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->saveGreeting();
        break;

    case in_array($path, ['/admin/line-config/contact', '/admin/line_config/contact', '/admin/line-settings/contact'], true) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->saveContact();
        break;

    case in_array($path, ['/admin/line-config/liff', '/admin/line_config/liff', '/admin/line-settings/liff'], true) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->saveLiff();
        break;

    case in_array($path, ['/admin/line-config/limit', '/admin/line_config/limit', '/admin/line-settings/limit'], true) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->saveMessageLimit();
        break;

    case in_array($path, ['/admin/line-config/photo', '/admin/line_config/photo', '/admin/line-settings/photo'], true) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->savePhotoIllustration();
        break;

    case in_array($path, ['/admin/line-config/buttons', '/admin/line_config/buttons', '/admin/line-settings/buttons'], true) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->saveButtons();
        break;

    case in_array($path, ['/admin/line-config/apply', '/admin/line_config/apply', '/admin/line-settings/apply'], true) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->applyRichMenu();
        break;

    case in_array($path, ['/admin/line-config/remove', '/admin/line_config/remove', '/admin/line-settings/remove'], true) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminLineConfigController.php';
        aiart_require_line_config_owner();
        (new AdminLineConfigController())->removeRichMenu();
        break;

    case $path === '/admin/richmenu-segments':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminRichMenuSegmentController.php';
        AdminAuthController::requireOwner();
        (new AdminRichMenuSegmentController())->show();
        break;

    case $path === '/admin/richmenu-segments/save' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminRichMenuSegmentController.php';
        AdminAuthController::requireOwner();
        (new AdminRichMenuSegmentController())->save();
        break;

    case $path === '/admin/richmenu-segments/create' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminRichMenuSegmentController.php';
        AdminAuthController::requireOwner();
        (new AdminRichMenuSegmentController())->create();
        break;

    case $path === '/admin/richmenu-segments/apply-online-generation' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminRichMenuSegmentController.php';
        AdminAuthController::requireOwner();
        (new AdminRichMenuSegmentController())->applyOnlineGeneration();
        break;

    case $path === '/admin/richmenu-segments/create-online-default' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminRichMenuSegmentController.php';
        AdminAuthController::requireOwner();
        (new AdminRichMenuSegmentController())->createOnlineDefault();
        break;

    case $path === '/admin/richmenu-segments/sync' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminRichMenuSegmentController.php';
        AdminAuthController::requireOwner();
        (new AdminRichMenuSegmentController())->sync();
        break;

    // Admin: QR郢ｧ・ｳ郢晢ｽｼ郢昴・
    case $path === '/admin/qrcode':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminQrController.php';
        if ($method === 'POST') {
            AdminAuthController::requireOwner();
        } else {
            AdminAuthController::requireLogin();
        }
        $ctrl = new AdminQrController();
        $method === 'POST' ? $ctrl->save() : $ctrl->show();
        break;

    // Admin: QR郢ｧ・ｳ郢晢ｽｼ郢昴・LINE ID陷台ｼ∝求
    case $path === '/admin/qrcode/delete' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminQrController.php';
        AdminAuthController::requireOwner();
        (new AdminQrController())->delete();
        break;

    // Admin: 闖ｴ・ｿ邵ｺ繝ｻ蟀ｿ郢晄ｧｭ繝ｫ郢晢ｽ･郢ｧ・｢郢晢ｽｫ
    case $path === '/admin/manual':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        AdminAuthController::requireLogin();
        $pageTitle = '闖ｴ・ｿ邵ｺ繝ｻ蟀ｿ郢晄ｧｭ繝ｫ郢晢ｽ･郢ｧ・｢郢晢ｽｫ';
        require BASE_PATH . '/app/Views/admin/manual.php';
        break;

    // 陞溷､慚夐ｶ・｣髫墓じ縺礼ｹ晢ｽｼ郢晁侭縺幃包ｽｨ邵ｺ・ｮworker隘搾ｽｷ陷崎ｼ斐♀郢晢ｽｳ郢晏ｳｨ繝ｻ郢ｧ・､郢晢ｽｳ郢晁肩・ｼ繝ｻron邵ｺ・ｮ闖ｫ譎槫験繝ｻ繝ｻ    // 關薙・ https://school.sengoku-ai.com/cron/run?token=XXXX
    case $path === '/cron/run':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Services/Logger.php';
        require_once BASE_PATH . '/app/Services/LineService.php';
        require_once BASE_PATH . '/app/Services/PromptService.php';
        require_once BASE_PATH . '/app/Services/ImageGenerationService.php';
        require_once BASE_PATH . '/app/Services/StorageService.php';
        require_once BASE_PATH . '/app/Services/SurveyDefinition.php';
        require_once BASE_PATH . '/app/Workers/GenerateImagesWorker.php';

        // 郢晏現繝ｻ郢ｧ・ｯ郢晢ｽｳ霎｣・ｧ陷ｷ闌ｨ・ｼ莠･繝ｻ陜玲ｧｭ縺・ｹｧ・ｯ郢ｧ・ｻ郢ｧ・ｹ隴弱ｅ竊馴明・ｪ陷肴・蜃ｽ隰瑚・・邵ｺ・ｦ闖ｫ譎擾ｽｭ蛛・ｽｼ繝ｻ        $savedToken = Settings::get('cron_token', '');
        if ($savedToken === '') {
            $savedToken = bin2hex(random_bytes(16));
            Settings::set('cron_token', $savedToken);
        }
        $given = $_GET['token'] ?? '';
        if (!hash_equals($savedToken, $given)) {
            http_response_code(403);
            echo 'Forbidden';
            break;
        }

        header('Content-Type: text/plain');
        try {
            $processed = 0;
            $worker = new GenerateImagesWorker();
            for ($i = 0; $i < 5; $i++) {
                if (!$worker->run()) {
                    break;
                }
                $processed++;
            }
            require_once BASE_PATH . '/app/Services/ReminderService.php';
            require_once BASE_PATH . '/app/Services/WaitlistNotificationService.php';
            require_once BASE_PATH . '/app/Services/ClassFollowupService.php';
            require_once BASE_PATH . '/app/Services/TenantService.php';
            require_once BASE_PATH . '/app/Services/CommonIntegrationService.php';

            $reminded = 0;
            $waitlistNotified = 0;
            $followups = 0;
            $integrationProcessed = 0;
            $originalTenantId = Settings::tenantId();
            $tenants = (new TenantService())->all();
            try {
                foreach ($tenants as $tenant) {
                    if (($tenant['status'] ?? '') !== 'active' || empty($tenant['id'])) {
                        continue;
                    }
                    Settings::useTenantId((int)$tenant['id']);
                    Settings::set('worker_last_run', date('Y-m-d H:i:s'));
                    $reminded += (new ReminderService())->dispatchDue();
                    $waitlistNotified += (new WaitlistNotificationService())->notifyOpenSlots();
                    $followups += (new ClassFollowupService())->dispatchDue();
                    try {
                        $integrationService = new CommonIntegrationService();
                        for ($j = 0; $j < 3; $j++) {
                            if (!$integrationService->processNext()) {
                                break;
                            }
                            $integrationProcessed++;
                        }
                    } catch (Throwable $integrationError) {
                        error_log('Common integration cron skipped: ' . $integrationError->getMessage());
                    }
                }
            } finally {
                Settings::useTenantId($originalTenantId);
            }
            echo "OK processed={$processed} reminded={$reminded} waitlist_notified={$waitlistNotified} followups={$followups} integration_outbox={$integrationProcessed} at " . date('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'ERROR: ' . $e->getMessage();
        }
        break;

    // 陷ｿ闍難ｽｬ蟶ｷ蜃ｽ陷ｷ莉｣・LIFF闔閧ｲ・ｴ繝ｻ縺咲ｹ晢ｽｬ郢晢ｽｳ郢敖郢晢ｽｼ繝ｻ莠･繝ｻ鬮｢蜈ｷ・ｼ繝ｻ
    // LIFF: 戦国クリエイター入陣ガチャ
    case $path === '/liff/gacha':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/GachaLiffController.php';
        (new GachaLiffController())->show();
        break;

    case $path === '/liff/gacha/status' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/GachaLiffController.php';
        (new GachaLiffController())->status();
        break;

    case $path === '/liff/gacha/draw' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/GachaLiffController.php';
        (new GachaLiffController())->draw();
        break;

    case $path === '/liff/gacha/interest' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/GachaLiffController.php';
        (new GachaLiffController())->interest();
        break;

    case $path === '/liff/generate':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffGenerateController.php';
        (new LiffGenerateController())->show();
        break;

    case $path === '/liff/generate/request' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffGenerateController.php';
        (new LiffGenerateController())->request();
        break;

    case $path === '/liff/calendar' || $path === '/liff':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        $operationType = Settings::get('service_operation_type', 'class_school');
        $onlineGenerationOnly = $operationType === 'online_generation'
            || (
                Settings::get('generation_online_enabled', '0') === '1'
                && Settings::get('class_mode_enabled', '1') !== '1'
            );
        if ($onlineGenerationOnly) {
            require_once BASE_PATH . '/app/Controllers/LiffGenerateController.php';
            (new LiffGenerateController())->show();
        } else {
            require_once BASE_PATH . '/app/Controllers/LiffCalendarController.php';
            (new LiffCalendarController())->show();
        }
        break;

    case $path === '/liff/shop':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffShopController.php';
        (new LiffShopController())->show();
        break;

    case $path === '/liff/shop/checkout' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffShopController.php';
        (new LiffShopController())->checkout();
        break;

    case $path === '/liff/profile':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffProfileController.php';
        (new LiffProfileController())->show();
        break;

    case $path === '/liff/profile/me' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffProfileController.php';
        (new LiffProfileController())->me();
        break;

    case $path === '/liff/profile/save' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffProfileController.php';
        (new LiffProfileController())->save();
        break;

    case $path === '/liff/survey':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffSurveyController.php';
        (new LiffSurveyController())->show();
        break;

    case $path === '/liff/survey/submit' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffSurveyController.php';
        (new LiffSurveyController())->submit();
        break;

    // LIFF闔閧ｲ・ｴﾐ善I繝ｻ莠･繝ｻ鬮｢荵昴・ID郢晏現繝ｻ郢ｧ・ｯ郢晢ｽｳ邵ｺ・ｧ髫ｱ蟠趣ｽｨ・ｼ繝ｻ繝ｻ
    case $path === '/liff/reserve' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffCalendarController.php';
        (new LiffCalendarController())->reserve();
        break;

    // LIFF: current user's waitlist status
    case $path === '/liff/waitlist/status' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffCalendarController.php';
        (new LiffCalendarController())->waitlistStatus();
        break;

    // LIFF: cancel current user's waitlist entry
    case $path === '/liff/waitlist/cancel' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffCalendarController.php';
        (new LiffCalendarController())->cancelWaitlist();
        break;

    case $path === '/liff/reservation/status' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffReservationCancelController.php';
        (new LiffReservationCancelController())->status();
        break;

    case $path === '/liff/reservation/cancel' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/LiffReservationCancelController.php';
        (new LiffReservationCancelController())->cancel();
        break;

    // Admin: 郢ｧ・ｫ郢晢ｽｬ郢晢ｽｳ郢敖郢晢ｽｼ髯ｦ・ｨ驕会ｽｺ
    // Admin: 戦国クリエイター入陣ガチャ
    case $path === '/admin/gacha':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminGachaController.php';
        AdminAuthController::requireLogin();
        (new AdminGachaController())->index();
        break;

    case $path === '/admin/gacha-settings':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminGachaController.php';
        AdminAuthController::requireOwner();
        (new AdminGachaController())->settings();
        break;

    case $path === '/admin/gacha/campaign' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminGachaController.php';
        AdminAuthController::requireOwner();
        (new AdminGachaController())->saveCampaign();
        break;

    case $path === '/admin/gacha/rarities' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminGachaController.php';
        AdminAuthController::requireOwner();
        (new AdminGachaController())->saveRarities();
        break;

    case $path === '/admin/gacha/prizes' && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminGachaController.php';
        AdminAuthController::requireOwner();
        (new AdminGachaController())->savePrizes();
        break;

    case preg_match('#^/admin/gacha/schedules/(\d+)/grant$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminGachaController.php';
        AdminAuthController::requireLogin();
        (new AdminGachaController())->grant((int)$m[1]);
        break;

    case preg_match('#^/admin/gacha/schedules/(\d+)/notify$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminGachaController.php';
        AdminAuthController::requireLogin();
        (new AdminGachaController())->notify((int)$m[1]);
        break;

    case $path === '/admin/calendar':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminCalendarController.php';
        AdminAuthController::requireLogin();
        (new AdminCalendarController())->show();
        break;

    // Admin: 驍ｨ・ｱ髫ｪ蛹ｻ繝ｻ郢ｧ・ｨ郢ｧ・ｯ郢ｧ・ｹ郢晄亢繝ｻ郢昴・
    case $path === '/admin/report':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminReportController.php';
        AdminAuthController::requireLogin();
        (new AdminReportController())->stats();
        break;

    // Admin: 隰ｫ蝣ｺ・ｽ諛莞溽ｹｧ・ｰ繝ｻ蛹ｻ縺檎ｹ晢ｽｼ郢晉ｿｫ繝ｻ陝・ｉ逡代・繝ｻ
    case $path === '/admin/logs':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminReportController.php';
        AdminAuthController::requireOwner();
        (new AdminReportController())->logs();
        break;

    // Admin: CSV郢ｧ・ｨ郢ｧ・ｯ郢ｧ・ｹ郢晄亢繝ｻ郢昴・
    case $path === '/admin/export/users':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminExportController.php';
        AdminAuthController::requireLogin();
        (new AdminExportController())->users();
        break;
    case $path === '/admin/export/attendance':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminExportController.php';
        AdminAuthController::requireLogin();
        (new AdminExportController())->attendance();
        break;
    case $path === '/admin/export/requests':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminExportController.php';
        AdminAuthController::requireLogin();
        (new AdminExportController())->requests();
        break;

    // Admin: 郢ｧ・ｮ郢晢ｽ｣郢晢ｽｩ郢晢ｽｪ郢晢ｽｼ
    case $path === '/admin/gallery':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminGalleryController.php';
        AdminAuthController::requireLogin();
        (new AdminGalleryController())->show();
        break;

    // Admin: 郢晁・繝｣郢ｧ・ｯ郢ｧ・｢郢昴・繝ｻ繝ｻ蛹ｻ縺檎ｹ晢ｽｼ郢晉ｿｫ繝ｻ陝・ｉ逡代・繝ｻ
    case $path === '/admin/backup':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminBackupController.php';
        AdminAuthController::requireOwner();
        (new AdminBackupController())->download();
        break;

    case ($path === '/stripe/webhook' || preg_match('#^/stripe/webhook/[A-Za-z0-9_-]+$#', $path)) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Stripe webhook endpoint is active.\n";
        echo "URL: " . $path . "\n";
        echo "Method for Stripe: POST\n";
        echo "Required event types:\n";
        echo "- checkout.session.completed\n";
        echo "- customer.subscription.deleted\n";
        echo "- invoice.payment_failed\n";
        echo "Webhook secret setting: " . (Settings::get('stripe_webhook_secret', '') !== '' ? "configured" : "not configured") . "\n";
        $tenant = Settings::currentTenant();
        echo "Tenant: " . ($tenant ? (($tenant['tenant_key'] ?? '') . ' / ' . ($tenant['name'] ?? '')) : 'not detected') . "\n";
        break;

    // Stripe Webhook繝ｻ蝓滂ｽｱ・ｺ雋ゆｺ･・ｮ蠕｡・ｺ繝ｻ・ｼ繝ｻ
    case ($path === '/stripe/webhook' || preg_match('#^/stripe/webhook/[A-Za-z0-9_-]+$#', $path)) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/StripeWebhookController.php';
        (new StripeWebhookController())->handle();
        break;

    case ($path === '/shopping/webhook' || preg_match('#^/shopping/webhook/[A-Za-z0-9_-]+$#', $path)) && $method === 'GET':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/ShoppingWebhookController.php';
        (new ShoppingWebhookController())->diagnostic();
        break;

    case ($path === '/shopping/webhook' || preg_match('#^/shopping/webhook/[A-Za-z0-9_-]+$#', $path)) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/ShoppingWebhookController.php';
        (new ShoppingWebhookController())->handle();
        break;

    case $path === '/liff/paid':
        require_once BASE_PATH . '/config/settings.php';
        header('Content-Type: text/html; charset=UTF-8');
        $type = (string)($_GET['type'] ?? '');
        $title = '決済が完了しました';
        $body = '購入内容を確認しています。反映まで少し時間がかかる場合があります。';
        if ($type === 'ticket') {
            $title = '回数券の購入が完了しました';
            $body = '購入した回数券は、予約や参加確認時に自動で反映されます。';
        } elseif ($type === 'subscription') {
            $title = '月額サブスクの登録が完了しました';
            $body = 'サブスク会員として、予約や参加確認時に自動で判定されます。';
        } elseif ($type === 'annual_subscription') {
            $title = '年額サブスクの登録が完了しました';
            $body = '年額サブスク会員として、予約や参加確認時に自動で判定されます。';
        } elseif (isset($_GET['attendance'])) {
            $title = '参加費のお支払いが完了しました';
            $body = '予約の支払い状況は自動で反映されます。反映されない場合は少し時間を置いて確認してください。';
        }
        $liffId = Settings::get('shop_liff_id', '');
        if ($liffId === '') {
            $liffId = Settings::get('liff_id', '');
        }
        $paidTenant = Settings::currentTenant();
        $paidTenantKey = trim((string)($paidTenant['tenant_key'] ?? ''));
        $paidTenantQuery = $paidTenantKey !== '' ? '?tenant=' . rawurlencode($paidTenantKey) : '';
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>';
        echo '<style>*,*::before,*::after{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f4f5f7;color:#111827}.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.card{width:min(480px,100%);background:#fff;border:1px solid #dfe3ec;border-radius:16px;padding:28px 20px;text-align:center;box-shadow:0 8px 24px rgba(16,24,40,.08)}.mark{width:64px;height:64px;border-radius:999px;background:#e8fff1;color:#16a34a;display:grid;place-items:center;font-size:36px;font-weight:900;margin:0 auto 14px}h1{font-size:22px;line-height:1.45;margin:0 0 10px;color:#111827}p{font-size:14px;line-height:1.8;margin:0;color:#52607a}.actions{display:grid;gap:10px;margin-top:22px}.btn{display:block;border-radius:10px;padding:13px 16px;text-decoration:none;font-size:15px;font-weight:800}.primary{background:#6d5df3;color:#fff}.secondary{background:#eef1f7;color:#334155}.note{font-size:12px;color:#8a94a6;margin-top:16px}</style></head><body>';
        echo '<main class="wrap"><section class="card"><div class="mark">✓</div><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<div class="actions"><a class="btn primary" href="/liff/shop' . htmlspecialchars($paidTenantQuery, ENT_QUOTES, 'UTF-8') . '">購入メニューへ戻る</a><a class="btn secondary" href="/liff/calendar' . htmlspecialchars($paidTenantQuery, ENT_QUOTES, 'UTF-8') . '">予約カレンダーへ戻る</a></div>';
        echo '<p class="note">LINE内で開いている場合は、この画面を閉じても大丈夫です。</p></section></main>';
        if ($liffId) {
            echo '<script>liff.init({liffId:' . json_encode($liffId) . '}).then(function(){setTimeout(function(){if(liff.isInClient())liff.closeWindow();},4500);}).catch(function(){});</script>';
        }
        echo '</body></html>';
        break;

    // 雎趣ｽｺ雋ゆｺ･・ｮ蠕｡・ｺ繝ｻ繝ｻ郢晢ｽｼ郢ｧ・ｸ繝ｻ繝ｻIFF邵ｺ・ｮsuccess_url繝ｻ繝ｻ
    case $path === '/liff/paid':
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>邵ｺ鬆鷹ｫｪ隰・ｼ費ｼ櫁楜蠕｡・ｺ繝ｻ/title>';
        echo '<script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>';
        echo '<style>body{font-family:sans-serif;text-align:center;padding:60px 20px;background:#f4f5f7;color:#1a202c}h1{color:#7c6af7}.btn{display:inline-block;margin-top:20px;padding:12px 24px;background:#7c6af7;color:#fff;border-radius:8px;text-decoration:none}</style></head><body>';
        echo '<h1>隨ｨ繝ｻ邵ｺ鬆鷹ｫｪ隰・ｼ費ｼ櫁楜蠕｡・ｺ繝ｻ/h1><p>邵ｺ豕檎崟陷会｣ｰ邵ｺ讙趣ｽ｢・ｺ陞ｳ螢ｹ・邵ｺ・ｾ邵ｺ蜉ｱ笳・ｸｲ繝ｻbr>驕抵ｽｺ髫ｱ髦ｪﾎ鍋ｹ昴・縺晉ｹ晢ｽｼ郢ｧ・ｸ郢ｧ魍・NE邵ｺ・ｫ邵ｺ莨・竏夲ｽ顔ｸｺ蜉ｱ竏ｪ邵ｺ蜉ｱ笳・ｸｲ繝ｻ/p>';
        echo '<p style="font-size:13px;color:#718096;margin-top:20px">邵ｺ阮吶・郢晏｣ｹ繝ｻ郢ｧ・ｸ邵ｺ・ｯ鬮｢蟲ｨﾂｧ邵ｺ・ｦ隶剃ｹ晢ｼ樒ｸｺ・ｾ邵ｺ蟶呻ｽ鍋ｸｲ繝ｻ/p>';
        $liffId = Settings::get('liff_id', '');
        if ($liffId) {
            echo '<script>liff.init({liffId:' . json_encode($liffId) . '}).then(function(){setTimeout(function(){if(liff.isInClient())liff.closeWindow();},2500);});</script>';
        }
        echo '</body></html>';
        break;

    // Admin: 郢昶・縺鍋ｹ昴・繝ｨ陞ｻ・･雎・ｽｴ
    case $path === '/admin/tickets':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminTicketController.php';
        require_once BASE_PATH . '/app/Services/TicketLog.php';
        AdminAuthController::requireLogin();
        (new AdminTicketController())->index();
        break;

    // Admin: 闔閧ｲ・ｴ繝ｻ・ｱ・･雎・ｽｴ
    case $path === '/admin/reservations':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminReservationController.php';
        require_once BASE_PATH . '/app/Services/ReservationEventLog.php';
        AdminAuthController::requireLogin();
        $ctrl = new AdminReservationController();
        $method === 'POST' ? $ctrl->delete() : $ctrl->index();
        break;

    // Admin: 郢ｧ・ｭ郢晢ｽ｣郢晢ｽｳ郢ｧ・ｻ郢晢ｽｫ陞ｻ・･雎・ｽｴ
    case $path === '/admin/cancellations':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminCancellationController.php';
        require_once BASE_PATH . '/app/Services/ReservationEventLog.php';
        AdminAuthController::requireLogin();
        (new AdminCancellationController())->index();
        break;

    // Admin: 雎趣ｽｺ雋ゆｺ･・ｱ・･雎・ｽｴ
    case $path === '/admin/payments':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminPaymentController.php';
        AdminAuthController::requireLogin();
        (new AdminPaymentController())->index();
        break;

    case preg_match('#^/admin/payments/(\d+)/refund$#', $path, $m) && $method === 'POST':
        require_once BASE_PATH . '/config/database.php';
        require_once BASE_PATH . '/config/settings.php';
        require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
        require_once BASE_PATH . '/app/Controllers/AdminPaymentController.php';
        AdminAuthController::requireLogin();
        (new AdminPaymentController())->refund((int)$m[1]);
        break;

    // 郢ｧ・､郢晢ｽｳ郢ｧ・ｹ郢晏現繝ｻ郢晢ｽｩ郢晢ｽｼ
    case $path === '/install' || $path === '/install.php':
        require_once BASE_PATH . '/install.php';
        break;

    // 404
    default:
        if (strpos($path, '/admin') === 0) {
            header('Location: /admin/dashboard');
        } else {
            http_response_code(404);
            echo '404 Not Found';
        }
}

