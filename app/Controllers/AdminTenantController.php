<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
require_once BASE_PATH . '/app/Services/TenantService.php';
require_once BASE_PATH . '/app/Services/TenantDataService.php';
require_once BASE_PATH . '/app/Services/TenantUsageService.php';
require_once BASE_PATH . '/app/Services/TenantErrorMonitorService.php';
require_once BASE_PATH . '/app/Services/TenantSetupChecklistService.php';
require_once BASE_PATH . '/app/Services/TenantConnectionCheckService.php';
require_once BASE_PATH . '/app/Services/TenantBackupService.php';
require_once BASE_PATH . '/app/Services/TenantOperationsAuditService.php';
require_once BASE_PATH . '/app/Services/GenerationRecoveryService.php';
require_once BASE_PATH . '/app/Services/RichMenuSegmentService.php';

class AdminTenantController {
    private PDO $pdo;
    private TenantService $tenants;
    private TenantDataService $tenantData;
    private TenantUsageService $tenantUsage;
    private TenantErrorMonitorService $tenantErrors;
    private TenantSetupChecklistService $setupChecklist;
    private TenantConnectionCheckService $connectionCheck;
    private TenantBackupService $tenantBackups;
    private TenantOperationsAuditService $operationsAudit;
    private GenerationRecoveryService $generationRecovery;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenants = new TenantService($this->pdo);
        $this->tenantData = new TenantDataService($this->pdo);
        $this->tenantUsage = new TenantUsageService($this->pdo);
        $this->tenantErrors = new TenantErrorMonitorService($this->pdo);
        $this->setupChecklist = new TenantSetupChecklistService($this->tenants);
        $this->connectionCheck = new TenantConnectionCheckService($this->tenants);
        $this->tenantBackups = new TenantBackupService($this->pdo);
        $this->operationsAudit = new TenantOperationsAuditService(
            $this->pdo,
            $this->tenants,
            $this->tenantData,
            $this->tenantErrors,
            $this->tenantBackups
        );
        $this->generationRecovery = new GenerationRecoveryService($this->pdo);
    }

    public function index(): void {
        $this->tenantData->ensureDataColumns();
        $tenants = $this->tenants->all();
        $currentTenant = $this->tenants->current();
        $dataDiagnostics = $this->tenantData->diagnostics();
        $tenantSummaries = $this->tenantUsage->summaries($tenants);
        $tenantErrorSummaries = $this->tenantErrors->summaries($tenants);
        $tenantSetupSummaries = $this->setupChecklist->summaries($tenants);
        foreach ($tenants as $tenant) {
            if (empty($tenant['is_default'])) {
                continue;
            }
            $tenantId = (int)($tenant['id'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }
            $tenantSetupSummaries[$tenantId] = [
                'score' => 100,
                'completed' => 0,
                'total' => 0,
                'default_managed' => true,
                'message' => '標準アカウントはAPI設定、LINE設定、公開ページ設定などの共通設定で管理します。',
                'groups' => [],
            ];
        }
        $saved = $_GET['saved'] ?? '';
        $error = $_GET['error'] ?? '';
        require BASE_PATH . '/app/Views/admin/tenants.php';
    }

    public function monthlyReport(): void {
        $tenants = $this->tenants->all();
        $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : date('Y-m');
        $report = $this->tenantUsage->monthlyReport($tenants, $month);
        if (($_GET['format'] ?? '') === 'csv' || ($_GET['export'] ?? '') === 'csv') {
            $this->downloadMonthlyReportCsv($report, $month);
        }
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
        $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
        $saved = $_GET['saved'] ?? '';
        $error = $_GET['error'] ?? '';
        require BASE_PATH . '/app/Views/admin/tenant_monthly_report.php';
    }

    public function create(): void {
        $tenant = [
            'id' => null,
            'tenant_key' => '',
            'name' => '',
            'service_name' => '',
            'primary_domain' => '',
            'status' => 'active',
            'memo' => '',
            'is_default' => 0,
        ];
        $tenantSettings = [
            'line_official_id' => '',
            'liff_id' => '',
            'shop_liff_id' => '',
            'generate_liff_id' => '',
            'stripe_subscription_price_id' => '',
            'stripe_annual_subscription_price_id' => '',
            'one_time_price_id' => '',
            'line_monthly_limit' => '5000',
            'daily_request_limit' => '2',
            'max_images_per_request' => '4',
            'workflow_approval_mode' => 'manual',
            'workflow_attendance_gate' => 'approved_and_time_window',
            'generation_access_mode' => 'class_attendance',
            'generation_window_start' => '',
            'generation_window_end' => '',
            'generation_window_message' => '',
            'first_visit_free_enabled' => '1',
        ];
        $adminSeed = ['name' => '', 'email' => '', 'role' => 'admin'];
        $mode = 'create';
        $error = $_GET['error'] ?? '';
        require BASE_PATH . '/app/Views/admin/tenant_form.php';
    }

    public function store(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $data = $this->input();
        $error = $this->validate($data);
        if ($error !== '') {
            $this->redirect('/admin/tenants/create?error=' . urlencode($error));
        }

        try {
            $tenantId = $this->tenants->create($data);
            $this->saveInitialTenantSettings($tenantId);
            $this->createInitialAdminIfRequested($tenantId);
        } catch (Throwable $e) {
            $this->redirect('/admin/tenants/create?error=' . urlencode('保存できませんでした。クライアントキーやドメインの重複を確認してください。'));
        }
        $this->redirect('/admin/tenants/' . $tenantId . '/settings?saved=created&next=setup');
    }

    public function edit(int $id): void {
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $mode = 'edit';
        $tenantSettings = [];
        $adminSeed = [];
        $error = $_GET['error'] ?? '';
        require BASE_PATH . '/app/Views/admin/tenant_form.php';
    }

    public function settings(int $id): void {
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $settings = $this->tenants->settings($id);
        $schema = $this->tenants->settingSchema();
        $tenantUrls = $this->tenantUrls($tenant);
        $saved = $_GET['saved'] ?? '';
        $error = $_GET['error'] ?? '';
        require BASE_PATH . '/app/Views/admin/tenant_settings.php';
    }

    public function diagnostics(int $id): void {
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $tenantUrls = $this->tenantUrls($tenant);
        $diagnostics = $this->connectionCheck->diagnostics($tenant, $tenantUrls);
        $operationsAudit = $this->operationsAudit->audit($tenant, $diagnostics);
        $tenantSettings = $this->tenants->settings($id);
        $staleMinutes = max(2, min(1440, (int)($tenantSettings['generation_stale_minutes'] ?? 10)));
        $recoveryResult = null;
        $saved = $_GET['saved'] ?? '';
        $error = $_GET['error'] ?? '';
        if ($saved === 'recovered') {
            $recoveryResult = [
                'requests_reset' => (int)($_GET['requests_reset'] ?? 0),
                'jobs_reset' => (int)($_GET['jobs_reset'] ?? 0),
                'jobs_queued' => (int)($_GET['jobs_queued'] ?? 0),
                'warnings_count' => (int)($_GET['warnings_count'] ?? 0),
            ];
        }
        require BASE_PATH . '/app/Views/admin/tenant_diagnostics.php';
    }

    public function recoverDiagnostics(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }

        $tenantSettings = $this->tenants->settings($id);
        $staleMinutes = max(2, min(1440, (int)($tenantSettings['generation_stale_minutes'] ?? 10)));
        try {
            $result = $this->generationRecovery->recoverTenant($id, $staleMinutes);
            $query = http_build_query([
                'saved' => 'recovered',
                'requests_reset' => (int)($result['requests_reset'] ?? 0),
                'jobs_reset' => (int)($result['jobs_reset'] ?? 0),
                'jobs_queued' => (int)($result['jobs_queued'] ?? 0),
                'warnings_count' => count($result['warnings'] ?? []),
            ]);
            $this->redirect('/admin/tenants/' . $id . '/diagnostics?' . $query);
        } catch (Throwable $e) {
            $this->redirect('/admin/tenants/' . $id . '/diagnostics?error=' . urlencode($e->getMessage()));
        }
    }

    public function backups(int $id): void {
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $backups = $this->tenantBackups->list($tenant);
        $saved = $_GET['saved'] ?? '';
        $error = $_GET['error'] ?? '';
        $restoreSummary = $this->restoreSummaryFromQuery();
        require BASE_PATH . '/app/Views/admin/tenant_backups.php';
    }

    public function createBackup(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        try {
            $this->tenantBackups->create($tenant);
            $this->redirect('/admin/tenants/' . $id . '/backups?saved=created');
        } catch (Throwable $e) {
            $this->redirect('/admin/tenants/' . $id . '/backups?error=' . urlencode($e->getMessage()));
        }
    }

    public function downloadBackup(int $id, string $backupId): void {
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $path = $this->tenantBackups->zipPath($tenant, $backupId);
        if ($path === null) {
            $this->redirect('/admin/tenants/' . $id . '/backups?error=' . urlencode('バックアップファイルが見つかりません。'));
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function restoreBackup(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $backupId = (string)($_POST['backup_id'] ?? '');
        try {
            $summary = $this->tenantBackups->restore($tenant, $backupId);
            $query = http_build_query([
                'saved' => 'restored',
                'tables' => (int)($summary['tables'] ?? 0),
                'rows' => (int)($summary['rows'] ?? 0),
                'assets' => (int)($summary['assets'] ?? 0),
            ]);
            $this->redirect('/admin/tenants/' . $id . '/backups?' . $query);
        } catch (Throwable $e) {
            $this->redirect('/admin/tenants/' . $id . '/backups?error=' . urlencode($e->getMessage()));
        }
    }

    public function update(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        if (!$this->tenants->find($id)) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $data = $this->input();
        $error = $this->validate($data);
        if ($error !== '') {
            $this->redirect('/admin/tenants/' . $id . '/edit?error=' . urlencode($error));
        }
        try {
            $this->tenants->update($id, $data);
        } catch (Throwable $e) {
            $this->redirect('/admin/tenants/' . $id . '/edit?error=' . urlencode('更新できませんでした。クライアントキーやドメインの重複を確認してください。'));
        }
        $this->redirect('/admin/tenants?saved=updated');
    }

    public function status(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        if (!empty($tenant['is_default']) && ($_POST['status'] ?? '') !== 'active') {
            $this->redirect('/admin/tenants?error=' . urlencode('標準クライアントは停止またはアーカイブできません。'));
        }
        $this->tenants->setStatus($id, $_POST['status'] ?? 'active');
        $this->redirect('/admin/tenants?saved=status');
    }

    public function makeDefault(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        if (!$this->tenants->find($id)) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $this->tenants->makeDefault($id);
        $this->redirect('/admin/tenants?saved=default');
    }

    public function switchTenant(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $tenant = $this->tenants->find($id);
        if (!$tenant || ($tenant['status'] ?? '') !== 'active') {
            $this->redirect('/admin/tenants?error=' . urlencode('有効なクライアントが見つかりません。'));
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['admin_tenant_id'] = (int)$tenant['id'];
        $_SESSION['admin_tenant_key'] = (string)$tenant['tenant_key'];
        $_SESSION['admin_tenant_name'] = (string)$tenant['name'];
        if (class_exists('Settings') && method_exists('Settings', 'reload')) {
            Settings::reload();
        }
        $this->redirect('/admin/dashboard?tenant_switched=1');
    }

    public function clearTenantSwitch(): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['admin_tenant_id'], $_SESSION['admin_tenant_key'], $_SESSION['admin_tenant_name']);
        if (class_exists('Settings') && method_exists('Settings', 'reload')) {
            Settings::reload();
        }
        $this->redirect('/admin/tenants?saved=cleared');
    }

    public function saveSettings(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        if (!$this->tenants->find($id)) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $this->tenants->saveSettings($id, $_POST);
        $this->redirect('/admin/tenants/' . $id . '/settings?saved=1');
    }

    public function syncRichMenu(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $tenant = $this->tenants->find($id);
        if (!$tenant || ($tenant['status'] ?? '') !== 'active') {
            $this->redirect('/admin/tenants?error=' . urlencode('有効なクライアントが見つかりません。'));
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $previous = [
            'admin_tenant_id' => $_SESSION['admin_tenant_id'] ?? null,
            'admin_tenant_key' => $_SESSION['admin_tenant_key'] ?? null,
            'admin_tenant_name' => $_SESSION['admin_tenant_name'] ?? null,
        ];

        try {
            $_SESSION['admin_tenant_id'] = (int)$tenant['id'];
            $_SESSION['admin_tenant_key'] = (string)$tenant['tenant_key'];
            $_SESSION['admin_tenant_name'] = (string)$tenant['name'];
            if (class_exists('Settings') && method_exists('Settings', 'reload')) {
                Settings::reload();
            }
            $limit = max(1, min(1000, (int)($_POST['limit'] ?? 500)));
            $result = (new RichMenuSegmentService())->syncAll($limit);
            $this->restoreTenantSession($previous);
            if (class_exists('Settings') && method_exists('Settings', 'reload')) {
                Settings::reload();
            }
            $query = http_build_query([
                'saved' => 'richmenu_synced',
                'ok' => (int)($result['success'] ?? 0),
                'ng' => (int)($result['failed'] ?? 0),
            ]);
            $this->redirect('/admin/tenants/' . $id . '/settings?' . $query);
        } catch (Throwable $e) {
            $this->restoreTenantSession($previous);
            if (class_exists('Settings') && method_exists('Settings', 'reload')) {
                Settings::reload();
            }
            $this->redirect('/admin/tenants/' . $id . '/settings?error=' . urlencode($e->getMessage()));
        }
    }

    public function exportHandover(int $id): void {
        $tenant = $this->tenants->find($id);
        if (!$tenant) {
            $this->redirect('/admin/tenants?error=' . urlencode('クライアントが見つかりません。'));
        }
        $settings = $this->tenants->settings($id);
        $schema = $this->tenants->settingSchema();
        $tenantUrls = $this->tenantUrls($tenant);
        $setup = $this->setupChecklist->summaries([$tenant])[$id] ?? [];
        $filename = 'tenant-handover-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)($tenant['tenant_key'] ?? $id)) . '.md';
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $this->handoverMarkdown($tenant, $settings, $schema, $tenantUrls, $setup);
        exit;
    }

    private function input(): array {
        return [
            'tenant_key' => trim((string)($_POST['tenant_key'] ?? '')),
            'name' => trim((string)($_POST['name'] ?? '')),
            'service_name' => trim((string)($_POST['service_name'] ?? '')),
            'primary_domain' => trim((string)($_POST['primary_domain'] ?? '')),
            'status' => (string)($_POST['status'] ?? 'active'),
            'memo' => trim((string)($_POST['memo'] ?? '')),
        ];
    }

    private function validate(array $data): string {
        if (($data['name'] ?? '') === '') {
            return 'クライアント名を入力してください。';
        }
        if (($data['tenant_key'] ?? '') === '') {
            return 'クライアントキーを入力してください。';
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string)$data['tenant_key'])) {
            return 'クライアントキーは英数字、ハイフン、アンダーバーで入力してください。';
        }
        return $this->validateInitialAdmin();
    }

    private function initialSettingsInput(): array {
        $keys = [
            'line_official_id',
            'liff_id',
            'shop_liff_id',
            'generate_liff_id',
            'stripe_subscription_price_id',
            'stripe_annual_subscription_price_id',
            'one_time_price_id',
            'line_monthly_limit',
            'daily_request_limit',
            'max_images_per_request',
            'workflow_approval_mode',
            'workflow_attendance_gate',
            'generation_access_mode',
            'generation_window_start',
            'generation_window_end',
            'generation_window_message',
            'first_visit_free_enabled',
        ];
        $settings = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $value = trim((string)$_POST[$key]);
            if ($value !== '') {
                $settings[$key] = $value;
            }
        }
        return $settings;
    }

    private function saveInitialTenantSettings(int $tenantId): void {
        $settings = $this->initialSettingsInput();
        if (!empty($settings)) {
            $this->tenants->saveSettings($tenantId, $settings);
        }
    }

    private function validateInitialAdmin(): string {
        $name = trim((string)($_POST['initial_admin_name'] ?? ''));
        $email = trim((string)($_POST['initial_admin_email'] ?? ''));
        $password = (string)($_POST['initial_admin_password'] ?? '');
        if ($name === '' && $email === '' && $password === '') {
            return '';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '初期管理者のメールアドレスを正しく入力してください。';
        }
        if (strlen($password) < 8) {
            return '初期管理者のパスワードは8文字以上で入力してください。';
        }
        AdminAuthController::ensureColumns($this->pdo);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        if ((int)$stmt->fetchColumn() > 0) {
            return '初期管理者のメールアドレスはすでに登録されています。';
        }
        return '';
    }

    private function createInitialAdminIfRequested(int $tenantId): void {
        $name = trim((string)($_POST['initial_admin_name'] ?? ''));
        $email = trim((string)($_POST['initial_admin_email'] ?? ''));
        $password = (string)($_POST['initial_admin_password'] ?? '');
        if ($email === '' && $password === '' && $name === '') {
            return;
        }
        AdminAuthController::ensureColumns($this->pdo);
        $role = (($_POST['initial_admin_role'] ?? 'admin') === 'staff') ? 'staff' : 'admin';
        $stmt = $this->pdo->prepare("
            INSERT INTO admin_users (email, name, role, tenant_id, status, password_hash, created_at)
            VALUES (?, ?, ?, ?, 'active', ?, ?)
        ");
        $stmt->execute([
            $email,
            $name !== '' ? $name : $email,
            $role,
            $tenantId,
            password_hash($password, PASSWORD_DEFAULT),
            date('Y-m-d H:i:s'),
        ]);
    }

    private function downloadMonthlyReportCsv(array $report, string $month): void {
        $filename = 'tenant-monthly-report-' . $month . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'クライアントキー',
            'クライアント名',
            'ユーザー',
            '開催',
            '予約',
            '承認',
            '出席',
            '画像依頼',
            '画像完了',
            '画像失敗',
            'LINE送信',
            '決済件数',
            '売上',
            '返金',
            '差引',
            '最新活動',
        ]);
        foreach (($report['rows'] ?? []) as $row) {
            $tenant = $row['tenant'] ?? [];
            fputcsv($out, [
                $tenant['tenant_key'] ?? '',
                $tenant['name'] ?? '',
                (int)($row['users'] ?? 0),
                (int)($row['classes'] ?? 0),
                (int)($row['reservations'] ?? 0),
                (int)($row['approved'] ?? 0),
                (int)($row['attended'] ?? 0),
                (int)($row['image_requests'] ?? 0),
                (int)($row['completed_images'] ?? 0),
                (int)($row['failed_images'] ?? 0),
                (int)($row['line_messages'] ?? 0),
                (int)($row['payments'] ?? 0),
                (int)($row['payment_amount'] ?? 0),
                (int)($row['refund_amount'] ?? 0),
                (int)($row['net_amount'] ?? 0),
                $row['latest_activity'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    private function restoreTenantSession(array $previous): void {
        foreach (['admin_tenant_id', 'admin_tenant_key', 'admin_tenant_name'] as $key) {
            if (array_key_exists($key, $previous) && $previous[$key] !== null) {
                $_SESSION[$key] = $previous[$key];
            } else {
                unset($_SESSION[$key]);
            }
        }
    }

    private function redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }

    private function tenantUrls(array $tenant): array {
        $base = $this->publicBaseUrl($tenant);
        $key = rawurlencode((string)($tenant['tenant_key'] ?? ''));
        return [
            'line_webhook' => $base . '/webhook/line/' . $key,
            'stripe_webhook' => $base . '/stripe/webhook/' . $key,
            'shopping_webhook' => $base . '/shopping/webhook/' . $key,
            'calendar_liff' => $base . '/liff/calendar?tenant=' . $key,
            'shop_liff' => $base . '/liff/shop?tenant=' . $key,
            'generate_liff' => $base . '/liff/generate?tenant=' . $key,
            'gacha_liff' => $base . '/liff/gacha?tenant=' . $key,
        ];
    }

    private function handoverMarkdown(array $tenant, array $settings, array $schema, array $tenantUrls, array $setup): string {
        $lines = [];
        $lines[] = '# クライアント導入・引き継ぎメモ';
        $lines[] = '';
        $lines[] = '作成日時: ' . date('Y-m-d H:i:s');
        $lines[] = '';
        $lines[] = '## クライアント';
        $lines[] = '- クライアント名: ' . $this->md($tenant['name'] ?? '');
        $lines[] = '- クライアントキー: `' . $this->md($tenant['tenant_key'] ?? '') . '`';
        $lines[] = '- サービス名: ' . $this->md($tenant['service_name'] ?? ($settings['service_name'] ?? ''));
        $lines[] = '- ドメイン: ' . $this->md($tenant['primary_domain'] ?? ($settings['public_base_url'] ?? ''));
        $lines[] = '- 状態: ' . $this->md($tenant['status'] ?? '');
        $lines[] = '';
        $lines[] = '## 専用URL';
        foreach ([
            'line_webhook' => 'LINE Webhook URL',
            'stripe_webhook' => 'Stripe Webhook URL',
            'shopping_webhook' => 'ショッピング Webhook URL',
            'calendar_liff' => '予約カレンダー LIFF URL',
            'shop_liff' => '購入ページ LIFF URL',
            'generate_liff' => '画像生成 LIFF URL',
            'gacha_liff' => 'ガチャ LIFF URL',
        ] as $key => $label) {
            $lines[] = '- ' . $label . ': ' . ($tenantUrls[$key] ?? '');
        }
        $lines[] = '';
        $lines[] = '## 初期設定チェック';
        $lines[] = '- 完了率: ' . (int)($setup['score'] ?? 0) . '%';
        $lines[] = '- 完了カテゴリ: ' . (int)($setup['completed'] ?? 0) . ' / ' . (int)($setup['total'] ?? 0);
        foreach (($setup['groups'] ?? []) as $group) {
            $status = (($group['status'] ?? '') === 'ok') ? 'OK' : '未設定あり';
            $lines[] = '- ' . $this->md($group['label'] ?? '設定') . ': ' . $status;
        }
        $lines[] = '';
        $lines[] = '## 設定値一覧';
        $lines[] = '| 区分 | 項目 | 状態 | 値 |';
        $lines[] = '|---|---|---|---|';
        foreach ($schema as $key => $meta) {
            $value = (string)($settings[$key] ?? '');
            $isSecret = in_array(($meta['type'] ?? ''), ['password'], true)
                || preg_match('/(secret|token|api_key|access_token|webhook)/i', (string)$key);
            $displayValue = $value === '' ? '-' : ($isSecret ? '設定済み（非表示）' : $value);
            $status = $value === '' ? '未設定' : '設定済み';
            $lines[] = '| ' . $this->md($meta['group'] ?? '') . ' | ' . $this->md($meta['label'] ?? $key) . ' | ' . $status . ' | ' . $this->md($displayValue) . ' |';
        }
        $lines[] = '';
        $lines[] = '## 導入時の確認';
        $lines[] = '1. LINE DevelopersにLINE Webhook URLを登録する';
        $lines[] = '2. 予約用LIFF、購入用LIFF、ガチャLIFFのエンドポイントを登録する';
        $lines[] = '3. Stripe DashboardにStripe Webhook URLを登録する';
        $lines[] = '4. Stripeの商品・Price IDをクライアント別設定に登録する';
        $lines[] = '5. AI APIキーと画像生成エンジンを登録する';
        $lines[] = '6. 予約、購入、画像生成、LINE通知の疎通確認を行う';
        $lines[] = '';
        $lines[] = '## 注意';
        $lines[] = '- このファイルにはシークレットキーやアクセストークンの実値は出力しません。';
        $lines[] = '- アップデートでは config/、storage/、uploads/ の顧客データを上書きしない前提です。';
        $lines[] = '- クライアント所有のLINE、Stripe、AI APIを使う場合、請求・配信・個人情報の責任範囲を事前に確認してください。';
        return implode("\n", $lines);
    }

    private function md($value): string {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$value);
        $text = str_replace("\n", '<br>', $text);
        $text = str_replace('|', '\\|', $text);
        return trim($text) !== '' ? $text : '-';
    }

    private function restoreSummaryFromQuery(): array {
        return [
            'tables' => (int)($_GET['tables'] ?? 0),
            'rows' => (int)($_GET['rows'] ?? 0),
            'assets' => (int)($_GET['assets'] ?? 0),
        ];
    }

    private function publicBaseUrl(array $tenant): string {
        $domain = trim((string)($tenant['primary_domain'] ?? ''));
        if ($domain !== '') {
            if (preg_match('#^https?://#', $domain)) {
                return rtrim($domain, '/');
            }
            return 'https://' . rtrim($domain, '/');
        }
        $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        return 'https://' . ($host ?: 'school.sengoku-ai.com');
    }
}
