<?php
$pageTitle = '操作ログ';
ob_start();

$loginLogs = $loginLogs ?? [];
$logs = $logs ?? [];
$tenants = $tenants ?? [];
$selectedTenantId = (int)($selectedTenantId ?? 0);

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$statusLabel = static function (string $status): string {
    if ($status === 'success') return 'ログイン成功';
    if ($status === 'failed') return 'ログイン失敗';
    if ($status === 'logout') return 'ログアウト';
    return $status;
};

$reasonLabel = static function (?string $reason): string {
    if ($reason === 'invalid_credentials') return 'メールまたはパスワード不一致';
    if ($reason === 'suspended') return '停止中アカウント';
    return $reason ?: '-';
};
?>

<style>
.log-grid{display:grid;gap:18px}
.log-card{background:#fff;border:1px solid #dfe3ec;border-radius:8px;overflow:hidden}
.log-card__head{padding:16px 18px;border-bottom:1px solid #dfe3ec;font-weight:800}
.log-card__body{padding:18px}
.log-table-wrap{overflow:auto}
.log-table{width:100%;border-collapse:collapse;min-width:900px}
.log-table th,.log-table td{padding:11px 12px;border-bottom:1px solid #e4e8f1;text-align:left;vertical-align:top}
.log-table th{font-size:12px;color:#66728b;background:#fafbfe}
.log-muted{color:#66728b;font-size:13px;line-height:1.7}
.log-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:800}
.log-badge.success{background:#dcfce7;color:#166534}
.log-badge.failed{background:#fee2e2;color:#b91c1c}
.log-badge.logout{background:#eef2ff;color:#3730a3}
.log-lines{display:grid;gap:8px;max-height:460px;overflow:auto}
.log-line{font-family:Consolas,monospace;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:9px 10px;white-space:pre-wrap;word-break:break-word;font-size:12px}
.log-filter{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.log-filter select{min-width:240px;padding:10px 12px;border:1px solid #d7dce8;border-radius:8px;background:#fff}
.log-filter button,.log-filter a{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border-radius:8px;border:1px solid #d7dce8;background:#fff;color:#111827;text-decoration:none;font-weight:700}
.log-filter button{background:#6f5cf6;color:#fff;border-color:#6f5cf6}
@media(max-width:960px){.log-card__body{padding:14px}.log-table{min-width:760px}}
</style>

<div class="log-grid">
    <section class="log-card">
        <div class="log-card__head">管理画面ログイン履歴</div>
        <div class="log-card__body">
            <p class="log-muted" style="margin-bottom:14px">管理者・スタッフのログイン成功、ログイン失敗、ログアウトを新しい順に最大200件表示します。</p>
            <form class="log-filter" method="GET" action="/admin/logs">
                <select name="tenant_id" aria-label="クライアントで絞り込み">
                    <option value="0">すべてのクライアント</option>
                    <?php foreach ($tenants as $tenant): ?>
                        <?php $tenantId = (int)($tenant['id'] ?? 0); ?>
                        <option value="<?= $tenantId ?>" <?= $selectedTenantId === $tenantId ? 'selected' : '' ?>>
                            <?= $h($tenant['name'] ?? ('ID ' . $tenantId)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">絞り込み</button>
                <?php if ($selectedTenantId > 0): ?>
                    <a href="/admin/logs">解除</a>
                <?php endif; ?>
            </form>
            <div class="log-table-wrap">
                <table class="log-table">
                    <thead>
                    <tr>
                        <th>日時</th>
                        <th>クライアント</th>
                        <th>状態</th>
                        <th>名前</th>
                        <th>メール</th>
                        <th>権限</th>
                        <th>理由</th>
                        <th>IP</th>
                        <th>端末情報</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($loginLogs)): ?>
                        <tr><td colspan="9" class="log-muted">ログイン履歴はまだありません。次回ログインから記録されます。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($loginLogs as $row): ?>
                        <?php $status = (string)($row['status'] ?? ''); ?>
                        <?php $tenantName = $row['tenant_name'] ?? ($row['tenant_key'] ?? '-'); ?>
                        <tr>
                            <td><?= $h($row['created_at'] ?? '') ?></td>
                            <td><?= $h($tenantName ?: '-') ?></td>
                            <td><span class="log-badge <?= $h($status) ?>"><?= $h($statusLabel($status)) ?></span></td>
                            <td><?= $h($row['admin_name'] ?? '-') ?></td>
                            <td><?= $h($row['email'] ?? '-') ?></td>
                            <td><?= $h($row['admin_role'] ?? '-') ?></td>
                            <td><?= $h($reasonLabel($row['reason'] ?? null)) ?></td>
                            <td><?= $h($row['ip_address'] ?? '-') ?></td>
                            <td><?= $h($row['user_agent'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="log-card">
        <div class="log-card__head">システムログ</div>
        <div class="log-card__body">
            <?php if (empty($logs)): ?>
                <p class="log-muted">表示できるシステムログはありません。</p>
            <?php else: ?>
                <div class="log-lines">
                    <?php foreach ($logs as $line): ?>
                        <div class="log-line"><?= $h($line) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
?>
