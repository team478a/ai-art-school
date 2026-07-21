<?php
$pageTitle = $pageTitle ?? 'リッチメニュー設定';
$tenantName = trim((string)($_SESSION['admin_tenant_name'] ?? ''));
$tenantKey = trim((string)($_SESSION['admin_tenant_key'] ?? ''));
$esc = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$csrf = static function (): string {
    return function_exists('csrf_field') ? csrf_field() : '';
};

$config = $config ?? [];
$labels = $labels ?? [];
$presetOptions = $presetOptions ?? [];
$presetDefinitions = $presetDefinitions ?? [];
$mode = (string)($config['delivery_mode'] ?? 'segments');

$actionLabels = [
    'url' => 'URLを開く',
    'message' => 'メッセージ送信',
];

$extraHead = ($extraHead ?? '') . <<<'HTML'
<style>
.rm-stack{display:grid;gap:16px}
.rm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px}
.rm-card{background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.rm-card-head{padding:14px 18px;border-bottom:1px solid var(--border);font-weight:700}
.rm-card-body{padding:18px}
.rm-muted{color:var(--muted);line-height:1.8}
.rm-help{background:rgba(124,106,247,.08);border:1px solid rgba(124,106,247,.22);border-radius:8px;padding:14px;line-height:1.8}
.rm-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.rm-table{width:100%;border-collapse:collapse}
.rm-table th,.rm-table td{border-bottom:1px solid var(--border);padding:10px;text-align:left;vertical-align:top}
.rm-table th{font-size:12px;color:var(--muted)}
.rm-button-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:14px}
.rm-button-box{border:1px solid var(--border);border-radius:8px;padding:14px;background:rgba(127,127,127,.04)}
.rm-inline{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rm-badge{display:inline-flex;align-items:center;gap:4px;border:1px solid rgba(124,106,247,.3);background:rgba(124,106,247,.1);color:var(--accent2);padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}
.rm-code{font-family:Consolas,monospace;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:2px 6px;word-break:break-all}
.rm-note{border-left:4px solid var(--accent);background:rgba(124,106,247,.08);padding:12px 14px;border-radius:6px;line-height:1.8}
.rm-url{font-family:Consolas,monospace}
@media(max-width:720px){.rm-inline{grid-template-columns:1fr}.rm-actions .btn{width:100%}}
</style>
HTML;

ob_start();
?>

<?php if (!empty($saved)): ?>
  <div class="alert alert-success">リッチメニュー設定を保存しました。</div>
<?php endif; ?>
<?php if (!empty($created)): ?>
  <div class="alert alert-success"><?= $esc($labels[$created] ?? $created) ?> のリッチメニューを作成しました。ID: <?= $esc($createdId ?? '') ?></div>
<?php endif; ?>
<?php if (!empty($defaultCreated)): ?>
  <div class="alert alert-success">共通の画像生成メニューを作成し、LINE公式アカウントのデフォルトメニューに設定しました。ID: <?= $esc($createdId ?? '') ?></div>
<?php endif; ?>
<?php if (!empty($synced)): ?>
  <div class="alert alert-success">リッチメニューを同期しました。成功 <?= $esc($_GET['ok'] ?? '0') ?> 件 / 失敗 <?= $esc($_GET['ng'] ?? '0') ?> 件</div>
<?php endif; ?>
<?php if (!empty($onlineMode)): ?>
  <div class="alert alert-success">画像生成だけのテンプレートに切り替えました。続けて「共通メニューを作成してLINEに表示」を押してください。</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error">エラー: <?= $esc($error) ?></div>
<?php endif; ?>

<div class="rm-stack">
  <section class="rm-card">
    <div class="rm-card-head">現在操作中のクライアント</div>
    <div class="rm-card-body rm-muted">
      <strong><?= $esc($tenantName !== '' ? $tenantName : '標準アカウント') ?></strong>
      <?php if ($tenantKey !== ''): ?>
        <span class="rm-badge">キー: <?= $esc($tenantKey) ?></span>
      <?php endif; ?>
      <p>この画面で作成・保存するリッチメニューは、現在操作中のクライアントに反映されます。</p>
    </div>
  </section>

  <section class="rm-card">
    <div class="rm-card-head">まず選ぶ設定</div>
    <div class="rm-card-body">
      <div class="rm-grid">
        <div class="rm-help">
          <strong>予約を使わず、画像生成だけで使う場合</strong><br>
          LINE下部のメニューを「画像生成」ボタンだけにします。ボタンを押すと生成ページを開くため、ユーザーがLINEに「生成」と入力する必要はありません。
          <form method="post" action="/admin/richmenu-segments/apply-online-generation" class="rm-actions" style="margin-top:12px" onsubmit="return confirm('画像生成のみの1ボタン設定に切り替えます。よろしいですか？');">
            <?= $csrf() ?>
            <button class="btn btn-primary" type="submit">画像生成だけの設定にする</button>
          </form>
        </div>
        <div class="rm-help">
          <strong>LINEに実際のメニューを表示する場合</strong><br>
          設定を保存しただけでは、LINE側のメニュー画像は変わりません。このボタンでLINE公式アカウントに共通メニューを作成し、全員に表示します。
          <?php if (empty($config['generation_liff_id'])): ?>
            <div class="rm-note" style="margin-top:8px">
              先にクライアント別設定の「画像生成用LIFF ID」を設定してください。LIFFのEndpoint URLには、その画面に表示される「画像生成 LIFF URL」を登録します。
            </div>
          <?php else: ?>
            <div style="margin-top:8px">画像生成用LIFF ID: <span class="rm-code"><?= $esc($config['generation_liff_id']) ?></span></div>
          <?php endif; ?>
          <?php if (!empty($config['online_default_id'])): ?>
            <div style="margin-top:8px">現在の共通メニューID: <span class="rm-code"><?= $esc($config['online_default_id']) ?></span></div>
          <?php endif; ?>
          <form method="post" action="/admin/richmenu-segments/create-online-default" class="rm-actions" style="margin-top:12px" onsubmit="return confirm('共通の画像生成メニューを作成し、LINEに表示します。よろしいですか？');">
            <?= $csrf() ?>
            <button class="btn btn-primary" type="submit">共通メニューを作成してLINEに表示</button>
          </form>
        </div>
      </div>
      <p class="rm-note" style="margin-top:14px">
        LINEで「生成」と入力すると、LINE公式アカウント側の通常メッセージとして扱われ、自動応答が返ることがあります。画像生成はリッチメニューの「URLを開く」ボタンから使う設計にしてください。
      </p>
    </div>
  </section>

  <section class="rm-card">
    <div class="rm-card-head">用途と自動URL</div>
    <div class="rm-card-body">
      <p class="rm-muted">「用途」を選ぶと、必要なURLや送信テキストが自動入力されます。通常はURLを手入力する必要はありません。</p>
      <div class="table-wrap">
        <table class="rm-table">
          <thead>
            <tr><th>用途</th><th>動作</th><th>自動入力されるURL / 送信内容</th></tr>
          </thead>
          <tbody>
            <?php foreach ($presetDefinitions as $key => $def): ?>
              <tr>
                <td><strong><?= $esc($presetOptions[$key] ?? $key) ?></strong><br><span class="rm-muted"><?= $esc($def['help'] ?? '') ?></span></td>
                <td><?= $esc($actionLabels[$def['action'] ?? ''] ?? ($def['action'] ?? '')) ?></td>
                <td><span class="rm-code"><?= $esc(($def['action'] ?? '') === 'url' ? ($def['url'] ?? '') : ($def['text'] ?? '')) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <form method="post" action="/admin/richmenu-segments/save" class="rm-stack" id="richmenu-form">
    <?= $csrf() ?>

    <section class="rm-card">
      <div class="rm-card-head">基本設定</div>
      <div class="rm-card-body">
        <div class="rm-grid">
          <div class="form-group">
            <label>表示方式</label>
            <select name="rich_menu_delivery_mode">
              <option value="default" <?= $mode === 'default' ? 'selected' : '' ?>>全員に共通メニューを表示</option>
              <option value="segments" <?= $mode !== 'default' ? 'selected' : '' ?>>ユーザー状態ごとに出し分ける</option>
            </select>
            <p class="rm-muted">予約を使わず画像生成だけで運用するクライアントは「全員に共通メニュー」を選んでください。</p>
          </div>
          <div class="form-group">
            <label>出し分けを有効にする</label>
            <label style="display:flex;gap:8px;align-items:center;color:var(--text);font-weight:700">
              <input type="checkbox" name="rich_menu_segments_enabled" value="1" <?= !empty($config['segments_enabled']) ? 'checked' : '' ?>>
              初回・参加済み・回数券・サブスクで個別メニューを使う
            </label>
            <p class="rm-muted">予約や購入状態を使わない場合はオフで問題ありません。</p>
          </div>
        </div>
      </div>
    </section>

    <section class="rm-card">
      <div class="rm-card-head">LINE側で作成済みのRich Menu IDを使う場合</div>
      <div class="rm-card-body">
        <p class="rm-muted">通常は空欄で大丈夫です。この画面の「作成」ボタンで自動作成したIDが入ります。</p>
        <div class="rm-grid">
          <?php foreach ($labels as $segment => $label): ?>
            <div class="form-group">
              <label><?= $esc($label) ?> Rich Menu ID</label>
              <input type="text" name="rich_menu_segment_<?= $esc($segment) ?>_id" value="<?= $esc($config[$segment . '_id'] ?? '') ?>" placeholder="richmenu-...">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <?php foreach ($labels as $segment => $label): ?>
      <section class="rm-card">
        <div class="rm-card-head"><?= $esc($label) ?> のボタン設定</div>
        <div class="rm-card-body">
          <p class="rm-muted">「用途」を選ぶと、動作・URL・送信テキストが自動入力されます。必要な場合だけ表示名を調整してください。</p>
          <div class="rm-button-grid">
            <?php for ($i = 1; $i <= 6; $i++): ?>
              <?php
                $base = 'rich_menu_segment_' . $segment . '_button_' . $i . '_';
                $local = $segment . '_button_' . $i . '_';
                $preset = (string)($config[$local . 'preset'] ?? ($i === 1 ? 'generate' : 'none'));
              ?>
              <div class="rm-button-box" data-button-box>
                <strong>ボタン <?= $i ?></strong>
                <div class="form-group" style="margin-top:10px">
                  <label>用途</label>
                  <select name="<?= $esc($base) ?>preset" data-preset-select>
                    <?php foreach ($presetOptions as $key => $name): ?>
                      <option value="<?= $esc($key) ?>" <?= $preset === $key ? 'selected' : '' ?>><?= $esc($name) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="rm-inline">
                  <div class="form-group">
                    <label>アイコン・短い文字</label>
                    <input type="text" name="<?= $esc($base) ?>icon" data-field="icon" value="<?= $esc($config[$local . 'icon'] ?? '') ?>">
                  </div>
                  <div class="form-group">
                    <label>表示名</label>
                    <input type="text" name="<?= $esc($base) ?>label" data-field="label" value="<?= $esc($config[$local . 'label'] ?? '') ?>">
                  </div>
                </div>
                <div class="form-group">
                  <label>動作</label>
                  <select name="<?= $esc($base) ?>action" data-field="action">
                    <?php $action = (string)($config[$local . 'action'] ?? ''); ?>
                    <option value="url" <?= $action === 'url' ? 'selected' : '' ?>>URLを開く</option>
                    <option value="message" <?= $action === 'message' ? 'selected' : '' ?>>メッセージ送信</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>送信テキスト</label>
                  <input type="text" name="<?= $esc($base) ?>text" data-field="text" value="<?= $esc($config[$local . 'text'] ?? '') ?>">
                </div>
                <div class="form-group">
                  <label>URL</label>
                  <input type="text" class="rm-url" name="<?= $esc($base) ?>url" data-field="url" value="<?= $esc($config[$local . 'url'] ?? '') ?>">
                </div>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </section>
    <?php endforeach; ?>

    <div class="rm-actions">
      <button class="btn btn-primary" type="submit">設定を保存</button>
      <a class="btn btn-secondary" href="/admin/richmenu-segments">再読み込み</a>
    </div>
  </form>

  <section class="rm-card">
    <div class="rm-card-head">LINE側へ作成・同期</div>
    <div class="rm-card-body">
      <p class="rm-muted">
        「作成」はLINE公式アカウント側にリッチメニューを作ります。「同期」は既存ユーザーの状態に合わせて個別リッチメニューを割り当てます。
        画像生成だけの運用では、上の「共通メニューを作成してLINEに表示」を使ってください。
      </p>
      <div class="rm-grid">
        <?php foreach ($labels as $segment => $label): ?>
          <div class="rm-help">
            <strong><?= $esc($label) ?></strong><br>
            現在のID: <span class="rm-code"><?= $esc($config[$segment . '_id'] ?? '未作成') ?></span>
            <form method="post" action="/admin/richmenu-segments/create" class="rm-actions" style="margin-top:10px" onsubmit="return confirm('<?= $esc($label) ?> のリッチメニューをLINE側に作成します。よろしいですか？');">
              <?= $csrf() ?>
              <input type="hidden" name="segment" value="<?= $esc($segment) ?>">
              <button class="btn btn-secondary" type="submit">このメニューを作成</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" action="/admin/richmenu-segments/sync" class="rm-actions" style="margin-top:16px" onsubmit="return confirm('現在のユーザー状態に合わせてリッチメニューを同期します。よろしいですか？');">
        <?= $csrf() ?>
        <input type="number" name="limit" value="500" min="1" max="5000" style="max-width:140px">
        <button class="btn btn-primary" type="submit">ユーザーへ同期</button>
      </form>
    </div>
  </section>
</div>

<script>
const RM_PRESETS = <?= json_encode($presetDefinitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

document.querySelectorAll('[data-preset-select]').forEach((select) => {
  select.addEventListener('change', () => {
    const box = select.closest('[data-button-box]');
    const preset = RM_PRESETS[select.value] || {};
    if (!box || select.value === 'custom_message' || select.value === 'custom_url') return;
    ['icon', 'label', 'text', 'url'].forEach((key) => {
      const field = box.querySelector(`[data-field="${key}"]`);
      if (field) field.value = preset[key] || '';
    });
    const action = box.querySelector('[data-field="action"]');
    if (action && preset.action) action.value = preset.action;
  });
});
</script>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
