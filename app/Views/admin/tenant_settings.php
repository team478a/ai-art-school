<?php
$pageTitle = 'クライアント別設定';
ob_start();

$groupLabels = [
    'basic' => '基本情報',
    'public' => '公開ページ・法務表記',
    'line' => 'LINE・LIFF',
    'richmenu' => 'リッチメニュー',
    'stripe' => 'Stripe・料金',
    'ai' => 'AI生成',
    'operation' => '運用ルール',
];
$groupLabels['integration'] = '5システム連携';

$groupHelp = [
    'basic' => 'クライアント名、サービス名、運用タイプなど、画面表示や初期設定確認の基準になる情報です。',
    'public' => 'LP、利用規約、プライバシーポリシー、特商法ページに反映する会社情報です。',
    'line' => 'クライアント所有のLINE公式アカウント、Messaging API、予約用・購入用・画像生成用LIFFを登録します。',
    'richmenu' => '初回、参加済み、回数券、サブスクなど、ユーザー状態ごとのLINEリッチメニューIDを登録します。',
    'stripe' => 'クライアント所有のStripeアカウントで作成したキー、Webhook、Price ID、料金表示を登録します。',
    'ai' => 'クライアントごとのAI APIキー、生成エンジン、人物向け・高品質向けの切替設定です。',
    'operation' => '予約承認、初回無料、参加条件、オンライン生成の受付日・受付時間・生成数などの運用ルールです。',
];
$groupHelp['integration'] = '共通IDと紹介関係を外部の共通基盤へ連携します。未設定または無効時は従来どおり単独で動作し、紹介情報は外部APIで確認できた場合だけ確定します。';

$groups = [];
foreach (($schema ?? []) as $key => $meta) {
    $group = $meta['group'] ?? 'basic';
    $groups[$group][$key] = $meta;
}

$e = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$fieldValue = static function (array $settings, string $key): string {
    return htmlspecialchars((string)($settings[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};

$csrf = static function (): string {
    return function_exists('csrf_field') ? csrf_field() : '';
};

$tenantId = (int)($tenant['id'] ?? 0);
$tenantName = (string)($tenant['name'] ?? '');
$tenantKey = (string)($tenant['tenant_key'] ?? '');
?>

<?php if ($saved === 'richmenu_synced'): ?>
  <div class="alert alert-success">
    リッチメニューを同期しました。成功 <?= (int)($_GET['ok'] ?? 0) ?>件 / 失敗 <?= (int)($_GET['ng'] ?? 0) ?>件
  </div>
<?php elseif (!empty($saved)): ?>
  <div class="alert alert-success">
    <?= ($saved === 'created') ? 'クライアントを作成しました。続けてクライアント別設定を確認してください。' : 'クライアント別設定を保存しました。' ?>
  </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="tenant-toolbar">
  <div class="tenant-toolbar-actions">
    <a class="btn btn-secondary" href="/admin/tenants">← クライアント一覧へ戻る</a>
    <a class="btn btn-secondary" href="/admin/tenants/<?= $tenantId ?>/handover">引き継ぎメモ</a>
    <a class="btn btn-secondary" href="/admin/tenants/<?= $tenantId ?>/diagnostics">疎通チェック</a>
    <a class="btn btn-secondary" href="/admin/tenants/<?= $tenantId ?>/backups">バックアップ</a>
  </div>
  <div class="tenant-current">
    対象: <strong><?= $e($tenantName) ?></strong>
    <code><?= $e($tenantKey) ?></code>
  </div>
</div>

<div class="card guide-card">
  <div class="card-header">クライアント操作中の設定場所</div>
  <div class="card-body">
    <p>
      クライアント操作中は、標準アカウント用のAPI設定ではなく、この画面でLINE、LIFF、Stripe、AI API、オンライン生成、リッチメニューを設定します。
      入力候補が決まっている項目はプルダウンから選択できます。
    </p>
  </div>
</div>

<div class="responsive-grid summary-grid">
  <div class="card">
    <div class="card-header">LINE・LIFF</div>
    <div class="card-body">
      <p>LINE公式ID、Channel Secret、Channel Access Token、予約用・購入用・画像生成用LIFF IDを登録します。</p>
      <a class="btn btn-secondary" href="#group-line">設定へ移動</a>
    </div>
  </div>
  <div class="card">
    <div class="card-header">オンライン生成</div>
    <div class="card-body">
      <p>予約なしで生成できるクライアントは、受付方式、生成可能日、受付時間、生成数を設定します。</p>
      <a class="btn btn-secondary" href="#group-operation">設定へ移動</a>
    </div>
  </div>
  <div class="card">
    <div class="card-header">リッチメニュー</div>
    <div class="card-body">
      <p>生成ボタン、購入ボタン、予約ボタンなどをクライアントごとに作成し、ユーザー状態で出し分けます。</p>
      <div class="button-row">
        <a class="btn btn-primary" href="/admin/richmenu-segments">リッチメニュー作成画面を開く</a>
        <a class="btn btn-secondary" href="#group-richmenu">ID欄へ移動</a>
      </div>
    </div>
  </div>
</div>

<div class="card" id="line-liff-guide">
  <div class="card-header">LINE公式ID・LIFF IDの設定手順</div>
  <div class="card-body">
    <div class="step-grid">
      <div class="step-box">
        <strong>1. LINE公式ID</strong>
        <p>LINE Official Account Managerで対象アカウントを開き、ベーシックIDまたはプレミアムIDを確認します。<code>@386ipbjr</code> のように @ 付きで入力します。</p>
      </div>
      <div class="step-box">
        <strong>2. 予約用LIFF ID</strong>
        <p>LINE DevelopersのLINE LoginチャネルでLIFFアプリを追加し、予約カレンダー用URLをエンドポイントに登録します。発行されたLIFF IDを入力します。</p>
      </div>
      <div class="step-box">
        <strong>3. 購入用LIFF ID</strong>
        <p>購入・会員メニュー専用に別のLIFFアプリを作成し、購入ページ用URLをエンドポイントに登録します。予約と購入を分けることで動作確認しやすくなります。</p>
      </div>
      <div class="step-box">
        <strong>4. 画像生成用LIFF ID</strong>
        <p>画像生成だけで使う場合は、LINE Loginチャネルに画像生成専用LIFFアプリを追加します。Endpoint URLには下の「画像生成 LIFF URL」をそのまま登録し、発行されたLIFF IDを入力してください。</p>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($tenantUrls)): ?>
<div class="card">
  <div class="card-header">クライアント専用URL</div>
  <div class="card-body">
    <p class="muted">LINE Developers、Stripe、LIFFに登録するURLです。コピーして各サービス側に設定してください。</p>
    <div class="url-list">
      <?php foreach ([
          'line_webhook' => 'LINE Webhook URL',
          'stripe_webhook' => 'Stripe Webhook URL',
          'calendar_liff' => '予約カレンダー LIFF URL',
          'shop_liff' => '購入ページ LIFF URL',
          'generate_liff' => '画像生成 LIFF URL',
          'gacha_liff' => 'ガチャ LIFF URL',
      ] as $urlKey => $urlLabel): ?>
        <div class="url-item">
          <div class="url-label"><?= $e($urlLabel) ?></div>
          <code><?= $e((string)($tenantUrls[$urlKey] ?? '')) ?></code>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card" id="richmenu-sync-guide">
  <div class="card-header">リッチメニュー同期</div>
  <div class="card-body">
    <p class="muted">
      Rich Menu IDを保存した後、既存ユーザーへ反映したい場合に同期します。
      初回、参加済み、回数券、サブスクの状態に応じて、このクライアントのLINEユーザーに個別メニューを紐づけます。
    </p>
    <form method="post" action="/admin/tenants/<?= $tenantId ?>/richmenu-sync" onsubmit="return confirm('このクライアントのLINEユーザーへリッチメニューを同期します。よろしいですか？');">
      <?= $csrf() ?>
      <input type="hidden" name="limit" value="500">
      <button class="btn btn-primary" type="submit">このクライアントのリッチメニューを同期</button>
    </form>
  </div>
</div>

<form method="post" action="/admin/tenants/<?= $tenantId ?>/settings">
  <?= $csrf() ?>
  <div class="responsive-grid">
    <?php foreach ($groupLabels as $groupKey => $groupLabel): ?>
      <?php if (empty($groups[$groupKey])) continue; ?>
      <div class="card" id="group-<?= $e($groupKey) ?>">
        <div class="card-header"><?= $e($groupLabel) ?></div>
        <div class="card-body">
          <p class="group-help"><?= $e($groupHelp[$groupKey] ?? '') ?></p>

          <?php if ($groupKey === 'richmenu'): ?>
            <div class="inline-guide">
              <strong>入力方法</strong>
              <p>
                通常は <a href="/admin/richmenu-segments">リッチメニュー作成画面</a> で作成します。
                作成後、LINEから返る <code>richmenu-...</code> IDがこの欄に自動保存されます。
                既にLINE側で作成済みの場合だけ、手動でIDを貼り付けてください。
              </p>
            </div>
          <?php endif; ?>

          <?php foreach ($groups[$groupKey] as $key => $meta): ?>
            <?php
              $type = (string)($meta['type'] ?? 'text');
              $name = $e($key);
              $currentValue = (string)($settings[$key] ?? '');
              $options = (isset($meta['options']) && is_array($meta['options'])) ? $meta['options'] : [];
              $hasOptions = !empty($options);
            ?>
            <div class="form-group">
              <label><?= $e($meta['label'] ?? $key) ?></label>
              <?php if ($type === 'checkbox'): ?>
                <input type="hidden" name="<?= $name ?>" value="0">
                <label class="check-row">
                  <input type="checkbox" name="<?= $name ?>" value="1" <?= ($currentValue === '1') ? 'checked' : '' ?>>
                  <span>有効にする</span>
                </label>
              <?php elseif ($type === 'textarea'): ?>
                <textarea name="<?= $name ?>" rows="4" placeholder="<?= $e($meta['placeholder'] ?? '') ?>"><?= $fieldValue($settings, $key) ?></textarea>
              <?php elseif ($type === 'select' || $hasOptions): ?>
                <select name="<?= $name ?>">
                  <option value="">選択してください</option>
                  <?php foreach ($options as $optionValue => $optionLabel): ?>
                    <option value="<?= $e($optionValue) ?>" <?= ($currentValue === (string)$optionValue) ? 'selected' : '' ?>><?= $e($optionLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input
                  type="<?= in_array($type, ['password', 'date', 'time', 'number'], true) ? $e($type) : 'text' ?>"
                  name="<?= $name ?>"
                  value="<?= $fieldValue($settings, $key) ?>"
                  placeholder="<?= $e($meta['placeholder'] ?? '') ?>"
                  autocomplete="off"
                >
              <?php endif; ?>
              <?php if (!empty($meta['help'])): ?>
                <div class="field-help"><?= $e($meta['help']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="save-actions">
    <button class="btn btn-primary" type="submit">クライアント別設定を保存</button>
    <a class="btn btn-secondary" href="/admin/tenants">キャンセル</a>
  </div>
</form>

<style>
.tenant-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.tenant-toolbar-actions, .button-row, .save-actions { display:flex; gap:10px; flex-wrap:wrap; }
.tenant-current { color:var(--muted); }
.tenant-current code { margin-left:8px; }
.guide-card, .summary-grid, #line-liff-guide, #richmenu-sync-guide { margin-bottom:18px; }
.card-body p, .muted, .group-help, .field-help { color:var(--muted); line-height:1.75; }
.step-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:12px; }
.step-box, .inline-guide, .url-item { padding:14px; border:1px solid var(--border); border-radius:8px; background:var(--surface-muted,var(--bg)); }
.step-box p, .inline-guide p { margin:8px 0 0; line-height:1.75; color:var(--muted); }
.url-list { display:grid; gap:10px; }
.url-item { display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap; }
.url-label { font-weight:700; min-width:180px; }
.url-item code { word-break:break-all; white-space:normal; }
.form-group { margin-bottom:16px; }
.form-group label:first-child { display:block; font-weight:700; margin-bottom:8px; }
.form-group input[type="text"], .form-group input[type="password"], .form-group input[type="number"], .form-group input[type="date"], .form-group input[type="time"], .form-group select, .form-group textarea { width:100%; }
.check-row { display:flex; align-items:center; gap:8px; font-weight:700; }
.field-help { font-size:13px; margin-top:6px; }
.save-actions { margin-top:16px; }
</style>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
?>
