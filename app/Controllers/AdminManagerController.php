<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Controllers/AdminAuthController.php';
require_once BASE_PATH . '/app/Services/TenantService.php';

class AdminManagerController {
    private PDO $pdo;
    private TenantService $tenants;

    public function __construct() {
        $this->pdo = get_pdo();
        AdminAuthController::ensureColumns($this->pdo);
        $this->tenants = new TenantService($this->pdo);
    }

    public function index(): void {
        $admins = $this->pdo->query("
            SELECT
                a.id, a.email, a.name, a.role, a.tenant_id, a.status, a.last_login_at, a.created_at,
                t.name AS tenant_name, t.tenant_key
            FROM admin_users a
            LEFT JOIN tenants t ON t.id = a.tenant_id
            ORDER BY CASE a.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, a.created_at ASC, a.id ASC
        ")->fetchAll();

        $tenants = $this->tenants->all();
        $saved = $_GET['saved'] ?? '';
        $error = $_GET['error'] ?? '';
        require BASE_PATH . '/app/Views/admin/managers.php';
    }

    public function store(): void {
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $role = $this->normalizeRole($_POST['role'] ?? 'staff');
        $tenantId = $this->normalizeTenantId($_POST['tenant_id'] ?? '', $role);
        $password = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectError('メールアドレスが正しくありません。');
        }
        if (strlen($password) < 8) {
            $this->redirectError('パスワードは8文字以上で入力してください。');
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        if ((int)$stmt->fetchColumn() > 0) {
            $this->redirectError('このメールアドレスはすでに登録されています。');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO admin_users (email, name, role, tenant_id, status, password_hash, created_at)
            VALUES (?, ?, ?, ?, 'active', ?, NOW())
        ");
        $stmt->execute([$email, $name, $role, $tenantId, password_hash($password, PASSWORD_DEFAULT)]);
        $this->redirectSaved($role === 'staff' ? 'staff_created' : 'created');
    }

    public function updateRole(int $id): void {
        $role = $this->normalizeRole($_POST['role'] ?? 'staff');
        $target = $this->findAdmin($id);
        if (!$target) {
            $this->redirectError('対象のアカウントが見つかりません。');
        }
        if (($target['role'] ?? '') === 'owner' && $role !== 'owner' && $this->ownerCount() <= 1) {
            $this->redirectError('最後のオーナー権限は変更できません。');
        }

        $tenantId = $role === 'owner' ? null : $this->normalizeTenantId($target['tenant_id'] ?? '', $role);
        $this->pdo->prepare('UPDATE admin_users SET role = ?, tenant_id = ? WHERE id = ?')
            ->execute([$role, $tenantId, $id]);
        $this->redirectSaved('role');
    }

    public function updateTenant(int $id): void {
        $target = $this->findAdmin($id);
        if (!$target) {
            $this->redirectError('対象のアカウントが見つかりません。');
        }

        $tenantId = $this->normalizeTenantId($_POST['tenant_id'] ?? '', (string)($target['role'] ?? 'staff'));
        $this->pdo->prepare('UPDATE admin_users SET tenant_id = ? WHERE id = ?')->execute([$tenantId, $id]);
        $this->redirectSaved('tenant');
    }

    public function updateStatus(int $id): void {
        $status = ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
        if ($id === (int)($_SESSION['admin_id'] ?? 0) && $status === 'suspended') {
            $this->redirectError('自分自身のアカウントは停止できません。');
        }

        $target = $this->findAdmin($id);
        if (!$target) {
            $this->redirectError('対象のアカウントが見つかりません。');
        }
        if (($target['role'] ?? '') === 'owner' && $status === 'suspended' && $this->ownerCount() <= 1) {
            $this->redirectError('最後のオーナーは停止できません。');
        }

        $this->pdo->prepare('UPDATE admin_users SET status = ? WHERE id = ?')->execute([$status, $id]);
        $this->redirectSaved('status');
    }

    public function resetPassword(int $id): void {
        $password = $_POST['password'] ?? '';
        if (strlen($password) < 8) {
            $this->redirectError('パスワードは8文字以上で入力してください。');
        }
        if (!$this->findAdmin($id)) {
            $this->redirectError('対象のアカウントが見つかりません。');
        }
        $this->pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        $this->redirectSaved('password');
    }

    public function delete(int $id): void {
        if ($id === (int)($_SESSION['admin_id'] ?? 0)) {
            $this->redirectError('自分自身のアカウントは削除できません。');
        }
        $target = $this->findAdmin($id);
        if (!$target) {
            $this->redirectError('対象のアカウントが見つかりません。');
        }
        if (($target['role'] ?? '') === 'owner' && $this->ownerCount() <= 1) {
            $this->redirectError('最後のオーナーは削除できません。');
        }
        $this->pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        $this->redirectSaved('deleted');
    }

    private function normalizeRole(string $role): string {
        return in_array($role, ['owner', 'admin', 'staff'], true) ? $role : 'staff';
    }

    private function normalizeTenantId($value, string $role): ?int {
        if ($role === 'owner' || $role === 'super_owner') {
            return null;
        }

        $tenantId = (int)$value;
        if ($tenantId <= 0) {
            return null;
        }

        return $this->tenants->find($tenantId) ? $tenantId : null;
    }

    private function findAdmin(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT id, role, tenant_id FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function ownerCount(): int {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM admin_users WHERE role='owner'")->fetchColumn();
    }

    private function redirectSaved(string $code): void {
        header('Location: /admin/managers?saved=' . urlencode($code));
        exit;
    }

    private function redirectError(string $message): void {
        header('Location: /admin/managers?error=' . urlencode($message));
        exit;
    }
}
