<?php
$pageTitle = 'ガチャ設定';
ob_start();

$summary = $summary ?? [];
$config = $config ?? [];
$campaign = $config['campaign'] ?? ($summary['campaign'] ?? []);
$rarities = $config['rarities'] ?? [];
$prizes = $config['prizes'] ?? [];
$message = (string)($message ?? '');
$error = (string)($error ?? '');

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$csrf = static function (): string {
    return function_exists('csrf_field') ? csrf_field() : '';
};
?>

<style>
.gacha-card{background:#fff;border:1px solid #dfe3ec;border-radius:8px;overflow:hidden}
.gacha-card__head{padding:16px 18px;border-bottom:1px solid #dfe3ec;font-weight:800}
.gacha-card__body{padding:18px}
.gacha-note{background:#fff7ed;border:1px solid #fdba74;border-radius:8px;color:#9a3412;padding:16px 18px;line-height:1.75;margin-bottom:24px}
.gacha-alert{border-radius:8px;padding:14px 16px;margin:0 0 18px}
.gacha-alert.ok{background:#dcfce7;border:1px solid #86efac;color:#166534}
.gacha-alert.ng{background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c}
.gacha-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.gacha-form-row{display:grid;gap:6px}
.gacha-label{font-size:13px;color:#64708a;font-weight:700}
.gacha-input,.gacha-select,.gacha-textarea{width:100%;border:1px solid #d9deea;border-radius:8px;background:#fff;padding:10px 12px;font:inherit}
.gacha-textarea{min-height:76px;resize:vertical}
.gacha-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.gacha-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #d9deea;border-radius:8px;background:#fff;color:#111827;text-decoration:none;font-weight:800;padding:10px 16px;cursor:pointer}
.gacha-btn.primary{background:#6d5df3;border-color:#6d5df3;color:#fff}
.gacha-table-wrap{overflow:auto}
.gacha-table{width:100%;border-collapse:collapse;min-width:760px}
.gacha-table th,.gacha-table td{padding:12px 14px;border-bottom:1px solid #e4e8f1;text-align:left;vertical-align:top}
.gacha-table th{font-size:13px;color:#66728b;background:#fafbfe}
.gacha-table input,.gacha-table select,.gacha-table textarea{min-width:88px}
.gacha-section{margin-bottom:24px}
.gacha-muted{color:#66728b;font-size:13px;line-height:1.7}
.gacha-badge{display:inline-flex;border-radius:999px;background:#f1f5f9;color:#475569;padding:4px 9px;font-size:12px;font-weight:800}
@media(max-width:960px){.gacha-form-grid{grid-template-columns:1fr}.gacha-card__body{padding:14px}}
</style>

<?php if ($message !== ''): ?>
    <div class="gacha-alert ok"><?= $h($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="gacha-alert ng"><?= $h($error) ?></div>
<?php endif; ?>

<div class="gacha-note">
    <strong>オーナー専用設定</strong><br>
    この画面では、ガチャの名称、参加権の有効期限、レア度の抽選比率、景品内容を変更できます。<br>
    運営者が日々使う「参加権付与」や「LINE案内」は <a href="/admin/gacha">ガチャ運用</a> で行います。
</div>

<section class="gacha-card gacha-section">
    <div class="gacha-card__head">ガチャ基本設定</div>
    <div class="gacha-card__body">
        <form method="post" action="/admin/gacha/campaign">
            <?= $csrf() ?>
            <div class="gacha-form-grid">
                <label class="gacha-form-row">
                    <span class="gacha-label">キャンペーン名</span>
                    <input class="gacha-input" name="name" value="<?= $h($campaign['name'] ?? '') ?>">
                </label>
                <label class="gacha-form-row">
                    <span class="gacha-label">参加権の有効日数</span>
                    <input class="gacha-input" type="number" min="1" max="365" name="default_expires_days" value="<?= (int)($campaign['default_expires_days'] ?? 14) ?>">
                </label>
            </div>
            <p class="gacha-muted">キャンペーン名はLIFF画面と管理画面に表示されます。有効日数は参加権を付与した日からの期限です。</p>
            <div class="gacha-actions">
                <button class="gacha-btn primary" type="submit">基本設定を保存</button>
            </div>
        </form>
    </div>
</section>

<section class="gacha-card gacha-section">
    <div class="gacha-card__head">レア度・抽選比率</div>
    <div class="gacha-card__body">
        <form method="post" action="/admin/gacha/rarities">
            <?= $csrf() ?>
            <div class="gacha-table-wrap">
                <table class="gacha-table">
                    <thead>
                    <tr>
                        <th>コード</th>
                        <th>表示名</th>
                        <th>抽選比率</th>
                        <th>並び順</th>
                        <th>色</th>
                        <th>演出動画URL</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rarities as $rarity): ?>
                        <tr>
                            <td>
                                <span class="gacha-badge"><?= $h($rarity['code'] ?? '') ?></span>
                                <input type="hidden" name="id[]" value="<?= (int)$rarity['id'] ?>">
                            </td>
                            <td><input class="gacha-input" name="name[]" value="<?= $h($rarity['name'] ?? '') ?>"></td>
                            <td><input class="gacha-input" type="number" min="0" name="weight[]" value="<?= (int)($rarity['weight'] ?? 0) ?>"></td>
                            <td><input class="gacha-input" type="number" name="sort_order[]" value="<?= (int)($rarity['sort_order'] ?? 0) ?>"></td>
                            <td><input class="gacha-input" name="color[]" value="<?= $h($rarity['color'] ?? '') ?>"></td>
                            <td><input class="gacha-input" name="video_url[]" value="<?= $h($rarity['video_url'] ?? '') ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><input class="gacha-input" name="new_code" placeholder="例: UR"></td>
                        <td><input class="gacha-input" name="new_name" placeholder="新しいレア度"></td>
                        <td><input class="gacha-input" type="number" min="0" name="new_weight" placeholder="100"></td>
                        <td><input class="gacha-input" type="number" name="new_sort_order" placeholder="60"></td>
                        <td><input class="gacha-input" name="new_color" placeholder="#8b5cf6"></td>
                        <td><input class="gacha-input" name="new_video_url" placeholder="https://..."></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <p class="gacha-muted">抽選比率は重みです。例: 7000と1000なら、おおよそ7:1の比率になります。0にすると抽選対象外です。</p>
            <div class="gacha-actions">
                <button class="gacha-btn primary" type="submit">レア度を保存</button>
            </div>
        </form>
    </div>
</section>

<section class="gacha-card gacha-section">
    <div class="gacha-card__head">景品設定</div>
    <div class="gacha-card__body">
        <form method="post" action="/admin/gacha/prizes">
            <?= $csrf() ?>
            <div class="gacha-table-wrap">
                <table class="gacha-table">
                    <thead>
                    <tr>
                        <th>有効</th>
                        <th>レア度</th>
                        <th>景品名</th>
                        <th>説明</th>
                        <th>期限日数</th>
                        <th>並び順</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($prizes as $prize): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="id[]" value="<?= (int)$prize['id'] ?>">
                                <input type="checkbox" name="active_id[]" value="<?= (int)$prize['id'] ?>" <?= !empty($prize['is_active']) ? 'checked' : '' ?>>
                            </td>
                            <td>
                                <select class="gacha-select" name="rarity_id[]">
                                    <?php foreach ($rarities as $rarity): ?>
                                        <option value="<?= (int)$rarity['id'] ?>" <?= (int)$rarity['id'] === (int)($prize['rarity_id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= $h(($rarity['code'] ?? '') . ' ' . ($rarity['name'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input class="gacha-input" name="name[]" value="<?= $h($prize['name'] ?? '') ?>"></td>
                            <td><textarea class="gacha-textarea" name="description[]"><?= $h($prize['description'] ?? '') ?></textarea></td>
                            <td><input class="gacha-input" type="number" min="1" max="365" name="expires_days[]" value="<?= $h($prize['expires_days'] ?? '') ?>"></td>
                            <td><input class="gacha-input" type="number" name="sort_order[]" value="<?= (int)($prize['sort_order'] ?? 0) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><span class="gacha-badge">追加</span></td>
                        <td>
                            <select class="gacha-select" name="new_rarity_id">
                                <option value="">選択</option>
                                <?php foreach ($rarities as $rarity): ?>
                                    <option value="<?= (int)$rarity['id'] ?>"><?= $h(($rarity['code'] ?? '') . ' ' . ($rarity['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input class="gacha-input" name="new_name" placeholder="新しい景品名"></td>
                        <td><textarea class="gacha-textarea" name="new_description" placeholder="参加者に表示する説明"></textarea></td>
                        <td><input class="gacha-input" type="number" min="1" max="365" name="new_expires_days" placeholder="14"></td>
                        <td><input class="gacha-input" type="number" name="new_sort_order" placeholder="10"></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <p class="gacha-muted">有効チェックを外すと、その景品は抽選に出なくなります。景品は同じレア度内でランダムに選ばれます。</p>
            <div class="gacha-actions">
                <button class="gacha-btn primary" type="submit">景品を保存</button>
            </div>
        </form>
    </div>
</section>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
?>
