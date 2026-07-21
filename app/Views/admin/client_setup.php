<?php
$pageTitle = '横展開・初期設定';
ob_start();

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$setting = static function (string $key, string $default = '') use ($settings): string {
    return (string)($settings[$key] ?? $default);
};
$checked = static function (string $key, string $default = '0') use ($settings): string {
    return ((string)($settings[$key] ?? $default) === '1') ? 'checked' : '';
};
$grouped = static function (array $items): array {
    $groups = [];
    foreach ($items as $item) {
        $groups[(string)($item['group'] ?? 'その他')][] = $item;
    }
    return $groups;
};

$checks = is_array($checks ?? null) ? $checks : [];
$healthChecks = is_array($healthChecks ?? null) ? $healthChecks : [];
$preflightChecks = is_array($preflightChecks ?? null) ? $preflightChecks : [];
$productionChecks = is_array($productionChecks ?? null) ? $productionChecks : [];
$snapshots = is_array($snapshots ?? null) ? $snapshots : [];
$workflowSettings = is_array($workflow ?? null) ? $workflow : (is_array($workflowSettings ?? null) ? $workflowSettings : []);
$templates = is_array($templates ?? null) ? $templates : [];
$done = 0;
foreach ($checks as $check) {
    if (!empty($check['ok'])) {
        $done++;
    }
}
$total = max(1, count($checks));
$percent = (int)round(($done / $total) * 100);
?>

<style>
.setup-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px}
.setup-card{background:#fff;border:1px solid #d9deea;border-radius:8px;overflow:hidden;margin-bottom:20px}
.setup-card h2{font-size:18px;margin:0;padding:18px 20px;border-bottom:1px solid #e3e7ef}
.setup-card .body{padding:20px}
.setup-card label{display:block;font-weight:700;margin:0 0 6px;color:#52617c}
.setup-card input,.setup-card select,.setup-card textarea{width:100%;box-sizing:border-box;border:1px solid #d8deea;border-radius:8px;padding:12px;margin:0 0 14px;font-size:15px;background:#fff}
.setup-card textarea{min-height:92px}
.setup-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
.btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #d8deea;border-radius:8px;padding:10px 14px;background:#fff;color:#111827;text-decoration:none;font-weight:700;cursor:pointer}
.btn.primary{background:#6d5df6;border-color:#6d5df6;color:#fff}
.btn.danger{background:#fff1f2;border-color:#fda4af;color:#dc2626}
.progress{height:10px;background:#edf0f7;border-radius:999px;overflow:hidden;margin-top:10px}
.progress span{display:block;height:100%;background:#6d5df6}
.check-list{display:grid;gap:10px}
.check-item{border:1px solid #e1e6f0;border-radius:8px;padding:12px;background:#fff}
.check-ok{color:#059669;font-weight:700}
.check-ng{color:#dc2626;font-weight:700}
.group-title{font-size:14px;color:#52617c;margin:18px 0 8px}
.note{color:#66708a;font-size:14px;line-height:1.7}
.message{border-radius:8px;padding:12px 14px;margin:0 0 18px}
.message.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.message.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.checkbox-row{display:grid;grid-template-columns:24px 1fr;gap:8px;align-items:start;margin:0 0 10px}
.checkbox-row input{width:auto;margin-top:4px}
.template-card{border:1px solid #e1e6f0;border-radius:8px;padding:14px;margin-bottom:10px}
.template-card strong{display:block;margin-bottom:4px}
.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
@media(max-width:760px){.setup-grid{grid-template-columns:1fr}.setup-actions .btn{width:100%}}
</style>

<?php if (isset($_GET['saved'])): ?>
  <div class="message ok">設定を保存しました。</div>
<?php endif; ?>
<?php if (isset($_GET['template_error'])): ?>
  <div class="message err">テンプレートを選択してください。</div>
<?php endif; ?>
<?php if (isset($_GET['import_error'])): ?>
  <div class="message err">設定ファイルの取り込みに失敗しました。JSON形式とファイル内容を確認してください。</div>
<?php endif; ?>
<?php if (isset($_GET['restore_error'])): ?>
  <div class="message err">スナップショットの復元に失敗しました。</div>
<?php endif; ?>

<section class="setup-card">
  <h2>導入状況</h2>
  <div class="body">
    <strong><?= $done ?> / <?= count($checks) ?> 項目完了</strong>
    <div class="progress"><span style="width:<?= $percent ?>%"></span></div>
    <p class="note">新規クライアント導入時に必要な基本設定、公開ページ、LINE、Stripe、AI、運用フローの状態を確認します。</p>
    <div class="setup-actions">
      <a class="btn" href="/admin/settings">API・決済設定</a>
      <a class="btn" href="/admin/line-config">LINE設定</a>
      <a class="btn" href="/admin/public-settings">公開ページ設定</a>
      <a class="btn" href="/admin/client-setup/checklist">チェックリスト出力</a>
      <a class="btn" href="/admin/client-setup/handover">引き継ぎ資料出力</a>
      <a class="btn" href="/admin/client-setup/guide">導入ガイド出力</a>
    </div>
  </div>
</section>

<div class="setup-grid">
  <section class="setup-card">
    <h2>1. 新規クライアント作成ウィザード</h2>
    <div class="body">
      <form method="post" action="/admin/client-setup/wizard">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <label>クライアント名</label>
        <input name="wizard_client_name" value="<?= $h($setting('client_name')) ?>" placeholder="例：アクア株式会社">
        <label>サービス名</label>
        <input name="wizard_service_name" value="<?= $h($setting('service_name', 'AIアート教室')) ?>" placeholder="例：AIアート教室">
        <label>教室名</label>
        <input name="wizard_classroom_name" value="<?= $h($setting('classroom_name', 'AIアート教室')) ?>" placeholder="例：AIアート教室">
        <label>公開URL</label>
        <input name="wizard_public_base_url" value="<?= $h($setting('public_base_url')) ?>" placeholder="https://example.com">
        <label>会社名</label>
        <input name="wizard_company_name" value="<?= $h($setting('client_company_name')) ?>">
        <label>問い合わせメール</label>
        <input name="wizard_contact_email" value="<?= $h($setting('client_contact_email')) ?>">
        <label>問い合わせ電話番号</label>
        <input name="wizard_contact_phone" value="<?= $h($setting('client_contact_phone')) ?>">
        <label>標準テンプレート</label>
        <select name="wizard_workflow_template">
          <?php foreach ($templates as $value => $template): ?>
            <option value="<?= $h($value) ?>" <?= $setting('workflow_template') === $value ? 'selected' : '' ?>><?= $h($template['label'] ?? $value) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn primary" type="submit">初期設定を作成</button>
      </form>
    </div>
  </section>

  <section class="setup-card">
    <h2>2. 設定テンプレート管理</h2>
    <div class="body">
      <?php foreach ($templates as $value => $template): ?>
        <div class="template-card">
          <strong><?= $h($template['label'] ?? $value) ?></strong>
          <div class="note"><?= $h($template['description'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
      <form method="post" action="/admin/client-setup/template">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <label>適用するテンプレート</label>
        <select name="template">
          <?php foreach ($templates as $value => $template): ?>
            <option value="<?= $h($value) ?>"><?= $h($template['label'] ?? $value) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="note">適用前に現在の設定スナップショットを自動保存します。テンプレートの内容で運用フローと生成制限を上書きします。</p>
        <button class="btn primary" type="submit">テンプレートを適用</button>
      </form>
    </div>
  </section>
</div>

<section class="setup-card">
  <h2>クライアント別設定</h2>
  <div class="body">
    <form method="post" action="/admin/client-setup">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <div class="setup-grid">
        <div>
          <label>クライアント名</label>
          <input name="client_name" value="<?= $h($setting('client_name')) ?>">
          <label>サービス名</label>
          <input name="service_name" value="<?= $h($setting('service_name')) ?>">
          <label>教室名</label>
          <input name="classroom_name" value="<?= $h($setting('classroom_name')) ?>">
          <label>説明文</label>
          <textarea name="service_tagline"><?= $h($setting('service_tagline')) ?></textarea>
          <label>公開URL</label>
          <input name="public_base_url" value="<?= $h($setting('public_base_url')) ?>" placeholder="https://example.com">
        </div>
        <div>
          <label>会社名</label>
          <input name="client_company_name" value="<?= $h($setting('client_company_name')) ?>">
          <label>運営者名</label>
          <input name="client_operator_name" value="<?= $h($setting('client_operator_name')) ?>">
          <label>問い合わせメール</label>
          <input name="client_contact_email" value="<?= $h($setting('client_contact_email')) ?>">
          <label>問い合わせ電話番号</label>
          <input name="client_contact_phone" value="<?= $h($setting('client_contact_phone')) ?>">
          <label>所在地</label>
          <input name="client_address" value="<?= $h($setting('client_address')) ?>">
        </div>
        <div>
          <label>運用テンプレート</label>
          <select name="workflow_template">
            <?php foreach (($workflowSettings['templates'] ?? []) as $value => $label): ?>
              <option value="<?= $h($value) ?>" <?= $setting('workflow_template') === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <label>承認方式</label>
          <select name="workflow_approval_mode">
            <?php foreach (($workflowSettings['approval_modes'] ?? []) as $value => $label): ?>
              <option value="<?= $h($value) ?>" <?= $setting('workflow_approval_mode') === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <label>支払い方式</label>
          <select name="workflow_payment_mode">
            <?php foreach (($workflowSettings['payment_modes'] ?? []) as $value => $label): ?>
              <option value="<?= $h($value) ?>" <?= $setting('workflow_payment_mode') === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <label>当日案内タイミング</label>
          <select name="workflow_day_notice_mode">
            <?php foreach (($workflowSettings['day_notice_modes'] ?? []) as $value => $label): ?>
              <option value="<?= $h($value) ?>" <?= $setting('workflow_day_notice_mode', $setting('workflow_day_notice')) === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <label>参加条件</label>
          <select name="workflow_attendance_gate">
            <?php foreach (($workflowSettings['attendance_gates'] ?? []) as $value => $label): ?>
              <option value="<?= $h($value) ?>" <?= $setting('workflow_attendance_gate') === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="checkbox-row"><input type="checkbox" name="workflow_auto_notice_enabled" value="1" <?= $checked('workflow_auto_notice_enabled', '1') ?>> <span>承認後・当日に自動案内を使う</span></label>
          <label class="checkbox-row"><input type="checkbox" name="workflow_first_visit_free" value="1" <?= $checked('workflow_first_visit_free', '1') ?>> <span>初回無料を使う</span></label>
          <label class="checkbox-row"><input type="checkbox" name="workflow_ticket_enabled" value="1" <?= $checked('workflow_ticket_enabled', '1') ?>> <span>回数券を使う</span></label>
          <label class="checkbox-row"><input type="checkbox" name="workflow_subscription_enabled" value="1" <?= $checked('workflow_subscription_enabled', '1') ?>> <span>サブスクを使う</span></label>
          <label class="checkbox-row"><input type="checkbox" name="workflow_cash_payment_enabled" value="1" <?= $checked('workflow_cash_payment_enabled', '1') ?>> <span>現金払いを使う</span></label>
        </div>
      </div>
      <button class="btn primary" type="submit">設定を保存</button>
    </form>
  </div>
</section>

<div class="setup-grid">
  <section class="setup-card">
    <h2>3. 公開前テスト</h2>
    <div class="body">
      <form method="post" action="/admin/client-setup/preflight">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <p class="note">URL、LINE、LIFF、Stripe、AIの設定状態を確認します。外部通信テストではなく、設定値と公開URL形式の確認です。</p>
        <button class="btn primary" type="submit">公開前チェックを記録</button>
      </form>
      <?php foreach ($grouped($preflightChecks) as $group => $items): ?>
        <div class="group-title"><?= $h($group) ?></div>
        <div class="check-list">
          <?php foreach ($items as $item): ?>
            <div class="check-item">
              <div class="<?= !empty($item['ok']) ? 'check-ok' : 'check-ng' ?>"><?= !empty($item['ok']) ? 'OK' : '要確認' ?>：<?= $h($item['label'] ?? '') ?></div>
              <div class="note"><?= $h($item['detail'] ?? ($item['fix'] ?? '')) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="setup-card">
    <h2>5. 本番切替チェック</h2>
    <div class="body">
      <?php foreach ($grouped($productionChecks) as $group => $items): ?>
        <div class="group-title"><?= $h($group) ?></div>
        <div class="check-list">
          <?php foreach ($items as $item): ?>
            <div class="check-item">
              <div class="<?= !empty($item['ok']) ? 'check-ok' : 'check-ng' ?>"><?= !empty($item['ok']) ? 'OK' : '要対応' ?>：<?= $h($item['label'] ?? '') ?></div>
              <div class="note"><?= $h($item['fix'] ?? '') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<div class="setup-grid">
  <section class="setup-card">
    <h2>4. バックアップ・復元</h2>
    <div class="body">
      <form method="post" action="/admin/client-setup/snapshot">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <label>メモ</label>
        <input name="memo" placeholder="例：本番公開前">
        <button class="btn primary" type="submit">現在の設定を保存</button>
      </form>
      <hr>
      <?php if (!$snapshots): ?>
        <p class="note">保存済みスナップショットはありません。</p>
      <?php endif; ?>
      <?php foreach ($snapshots as $snapshot): ?>
        <div class="check-item">
          <strong><?= $h($snapshot['created_at'] ?? '') ?></strong>
          <div class="note mono"><?= $h($snapshot['file'] ?? '') ?></div>
          <div class="setup-actions">
            <form method="post" action="/admin/client-setup/restore" onsubmit="return confirm('このスナップショットを全体復元しますか？');">
              <?= function_exists('csrf_field') ? csrf_field() : '' ?>
              <input type="hidden" name="file" value="<?= $h($snapshot['file'] ?? '') ?>">
              <button class="btn danger" type="submit">全体復元</button>
            </form>
            <form method="post" action="/admin/client-setup/restore-partial" onsubmit="return confirm('選択カテゴリだけ復元しますか？');">
              <?= function_exists('csrf_field') ? csrf_field() : '' ?>
              <input type="hidden" name="file" value="<?= $h($snapshot['file'] ?? '') ?>">
              <select name="category">
                <option value="basic">基本情報のみ</option>
                <option value="public">公開ページ情報のみ</option>
                <option value="workflow">運用フローのみ</option>
                <option value="pricing">料金表示のみ</option>
                <option value="ai">AI品質・生成制限のみ</option>
              </select>
              <button class="btn" type="submit">部分復元</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="setup-card">
    <h2>6. 導入ドキュメント出力</h2>
    <div class="body">
      <p class="note">新規クライアントに渡す導入ガイド、初期設定チェックリスト、引き継ぎ資料、設定JSONを出力します。</p>
      <div class="setup-actions">
        <a class="btn" href="/admin/client-setup/guide">導入ガイド</a>
        <a class="btn" href="/admin/client-setup/checklist">チェックリスト</a>
        <a class="btn" href="/admin/client-setup/handover">引き継ぎ資料</a>
        <a class="btn" href="/admin/client-setup/export">設定JSON</a>
      </div>
      <hr>
      <form method="post" action="/admin/client-setup/import" enctype="multipart/form-data">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <label>設定JSONを取り込む</label>
        <input type="file" name="settings_file" accept="application/json,.json">
        <button class="btn" type="submit">設定を取り込む</button>
      </form>
    </div>
  </section>
</div>

<section class="setup-card">
  <h2>通常チェック</h2>
  <div class="body">
    <?php foreach ($grouped($checks) as $group => $items): ?>
      <div class="group-title"><?= $h($group) ?></div>
      <div class="check-list">
        <?php foreach ($items as $item): ?>
          <div class="check-item">
            <div class="<?= !empty($item['ok']) ? 'check-ok' : 'check-ng' ?>"><?= !empty($item['ok']) ? 'OK' : '未設定' ?>：<?= $h($item['label'] ?? '') ?></div>
            <div class="note"><?= $h($item['fix'] ?? '') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="setup-card">
  <h2>運用メモ</h2>
  <div class="body note">
    <p>横展開は当面、クライアント別コピー展開を前提にします。DB、uploads、storage、config/db.php、config/installed.lockは案件ごとに分離してください。</p>
    <p>アップデートZIPは共通本体として使い、設定・顧客データ・画像データは上書きしない運用を維持します。</p>
    <p>LINE公式、Stripe、AI APIキーはクライアント所有を標準にします。責任範囲を明確にするため、本番公開前にキー所有者、Webhook、公開ページ表記を必ず確認してください。</p>
  </div>
</section>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
?>
