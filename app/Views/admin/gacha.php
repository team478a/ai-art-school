<?php
$pageTitle = 'ガチャ運用';
ob_start();

$summary = $summary ?? [];
$campaign = $summary['campaign'] ?? [];
$schedules = $schedules ?? [];
$results = $results ?? [];
$interests = $interests ?? [];
$message = (string)($message ?? '');
$error = (string)($error ?? '');
$gachaTenant = class_exists('Settings') ? Settings::currentTenant() : null;
$gachaTenantKey = trim((string)($gachaTenant['tenant_key'] ?? ''));
$gachaTenantQuery = $gachaTenantKey !== '' ? '?tenant=' . rawurlencode($gachaTenantKey) : '';

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$csrf = static function (): string {
    return function_exists('csrf_field') ? csrf_field() : '';
};
?>

<style>
.gacha-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:18px 0 24px}
.gacha-card{background:#fff;border:1px solid #dfe3ec;border-radius:8px;overflow:hidden}
.gacha-card__head{padding:16px 18px;border-bottom:1px solid #dfe3ec;font-weight:800}
.gacha-card__body{padding:18px}
.gacha-number{font-size:42px;line-height:1.1;font-weight:900;color:#7464f4}
.gacha-note{background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;color:#312e81;padding:16px 18px;line-height:1.75;margin-bottom:24px}
.gacha-alert{border-radius:8px;padding:14px 16px;margin:0 0 18px}
.gacha-alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534}
.gacha-alert.ng{background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c}
.gacha-actions{display:flex;gap:10px;flex-wrap:wrap}
.gacha-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #d9deea;border-radius:8px;background:#fff;color:#111827;text-decoration:none;font-weight:800;padding:10px 16px;cursor:pointer}
.gacha-btn.primary{background:#6d5df3;border-color:#6d5df3;color:#fff}
.gacha-table-wrap{overflow:auto}
.gacha-table{width:100%;border-collapse:collapse;min-width:760px}
.gacha-table th,.gacha-table td{padding:12px 14px;border-bottom:1px solid #e4e8f1;text-align:left;vertical-align:top}
.gacha-table th{font-size:13px;color:#66728b;background:#fafbfe}
.gacha-section{margin-bottom:24px}
.gacha-muted{color:#66728b;font-size:13px;line-height:1.7}
@media(max-width:960px){.gacha-grid{grid-template-columns:1fr}.gacha-card__body{padding:14px}.gacha-number{font-size:34px}}
</style>

<?php if ($message !== ''): ?>
    <div class="gacha-alert ok"><?= $h($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="gacha-alert ng"><?= $h($error) ?></div>
<?php endif; ?>

<div class="gacha-note">
    <strong>運用フロー</strong><br>
    1. 教室参加後、対象開催日の「参加権を付与」を押します。<br>
    2. 「LINEで案内」を押すと、対象者へガチャURLを送信します。<br>
    3. 参加者はLIFFで1回だけ抽選できます。抽選結果と購入希望はこの画面で確認できます。<br>
    4. ガチャ名称、レア度、景品内容の変更は、オーナー専用の「ガチャ設定」で行います。<br>
    ガチャURL: <a href="/liff/gacha<?= $h($gachaTenantQuery) ?>">/liff/gacha<?= $h($gachaTenantQuery) ?></a>
</div>

<div class="gacha-grid">
    <div class="gacha-card">
        <div class="gacha-card__body">
            <div class="gacha-muted">現在のキャンペーン</div>
            <div class="gacha-number" style="font-size:36px"><?= $h($campaign['name'] ?? 'ガチャ') ?></div>
        </div>
    </div>
    <div class="gacha-card">
        <div class="gacha-card__body">
            <div class="gacha-muted">参加権</div>
            <div class="gacha-number"><?= (int)($summary['entitlements'] ?? 0) ?></div>
        </div>
    </div>
    <div class="gacha-card">
        <div class="gacha-card__body">
            <div class="gacha-muted">抽選済み</div>
            <div class="gacha-number"><?= (int)($summary['drawn'] ?? 0) ?></div>
        </div>
    </div>
    <div class="gacha-card">
        <div class="gacha-card__body">
            <div class="gacha-muted">購入希望</div>
            <div class="gacha-number"><?= (int)($summary['interests'] ?? 0) ?></div>
        </div>
    </div>
</div>

<section class="gacha-card gacha-section">
    <div class="gacha-card__head">開催日ごとの参加権付与</div>
    <div class="gacha-table-wrap">
        <table class="gacha-table">
            <thead>
            <tr>
                <th>開催日</th>
                <th>教室</th>
                <th>参加者</th>
                <th>付与済み</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($schedules)): ?>
                <tr><td colspan="5" class="gacha-muted">対象の開催日はありません。</td></tr>
            <?php endif; ?>
            <?php foreach ($schedules as $schedule): ?>
                <tr>
                    <td><?= $h(($schedule['class_date'] ?? '') . ' ' . substr((string)($schedule['start_time'] ?? ''), 0, 5) . '-' . substr((string)($schedule['end_time'] ?? ''), 0, 5)) ?></td>
                    <td><?= $h($schedule['title'] ?? '') ?></td>
                    <td><?= (int)($schedule['attended_count'] ?? 0) ?>人</td>
                    <td><?= (int)($schedule['entitlement_count'] ?? 0) ?>件</td>
                    <td>
                        <div class="gacha-actions">
                            <form method="post" action="/admin/gacha/schedules/<?= (int)$schedule['id'] ?>/grant">
                                <?= $csrf() ?>
                                <button class="gacha-btn primary" type="submit">参加権を付与</button>
                            </form>
                            <form method="post" action="/admin/gacha/schedules/<?= (int)$schedule['id'] ?>/notify">
                                <?= $csrf() ?>
                                <button class="gacha-btn" type="submit">LINEで案内</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="gacha-card gacha-section">
    <div class="gacha-card__head">最近の抽選結果</div>
    <div class="gacha-table-wrap">
        <table class="gacha-table">
            <thead>
            <tr>
                <th>日時</th>
                <th>ユーザー</th>
                <th>教室</th>
                <th>レア度</th>
                <th>景品</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($results)): ?>
                <tr><td colspan="5" class="gacha-muted">抽選結果はまだありません。</td></tr>
            <?php endif; ?>
            <?php foreach ($results as $result): ?>
                <tr>
                    <td><?= $h($result['drawn_at'] ?? $result['created_at'] ?? '') ?></td>
                    <td><?= $h($result['display_name'] ?? ('User ID ' . ($result['user_id'] ?? '-'))) ?></td>
                    <td><?= $h(($result['class_title'] ?? '-') . ' ' . ($result['class_date'] ?? '')) ?></td>
                    <td><?= $h($result['rarity_name'] ?? '') ?></td>
                    <td><?= $h($result['prize_name'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="gacha-card gacha-section">
    <div class="gacha-card__head">購入希望</div>
    <div class="gacha-table-wrap">
        <table class="gacha-table">
            <thead>
            <tr>
                <th>日時</th>
                <th>ユーザー</th>
                <th>景品</th>
                <th>メモ</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($interests)): ?>
                <tr><td colspan="4" class="gacha-muted">購入希望はまだありません。</td></tr>
            <?php endif; ?>
            <?php foreach ($interests as $interest): ?>
                <tr>
                    <td><?= $h($interest['created_at'] ?? '') ?></td>
                    <td><?= $h($interest['display_name'] ?? ('User ID ' . ($interest['user_id'] ?? '-'))) ?></td>
                    <td><?= $h(($interest['rarity_name'] ?? '') . ' / ' . ($interest['prize_name'] ?? '')) ?></td>
                    <td><?= $h($interest['message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
?>
