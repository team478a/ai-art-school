<?php
$pageTitle = ($mode ?? '') === 'edit' ? 'クライアント編集' : 'クライアント追加';
$isEdit = ($mode ?? '') === 'edit';
$action = $isEdit ? '/admin/tenants/' . (int)$tenant['id'] : '/admin/tenants';

$settingValue = static function (array $tenantSettings, string $key, string $default = ''): string {
    return htmlspecialchars((string)($tenantSettings[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

$adminValue = static function (array $adminSeed, string $key, string $default = ''): string {
    return htmlspecialchars((string)($adminSeed[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};

ob_start();
?>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap">
  <a class="btn btn-secondary" href="/admin/tenants">← クライアント一覧へ戻る</a>
  <?php if (!$isEdit): ?>
    <a class="btn btn-secondary" href="/admin/client-setup">横展開・初期設定へ</a>
  <?php endif; ?>
</div>

<div class="responsive-grid">
  <div class="card">
    <div class="card-header"><?= $isEdit ? 'クライアント情報を編集' : '新しいクライアントを追加' ?></div>
    <div class="card-body">
      <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>">
        <?= csrf_field() ?>

        <div class="form-group">
          <label>クライアントキー</label>
          <input type="text" name="tenant_key" value="<?= htmlspecialchars($tenant['tenant_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例: aqua-art-class" required>
          <div style="font-size:12px;color:var(--muted);margin-top:6px">英数字、ハイフン、アンダーバーで入力します。URLや設定の識別子に使います。</div>
        </div>

        <div class="form-group">
          <label>クライアント名</label>
          <input type="text" name="name" value="<?= htmlspecialchars($tenant['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例: アクア株式会社" required>
        </div>

        <div class="form-group">
          <label>サービス名</label>
          <input type="text" name="service_name" value="<?= htmlspecialchars($tenant['service_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例: AIアート教室">
        </div>

        <div class="form-group">
          <label>メインドメイン</label>
          <input type="text" name="primary_domain" value="<?= htmlspecialchars($tenant['primary_domain'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例: school.example.com">
          <div style="font-size:12px;color:var(--muted);margin-top:6px">https:// は省略できます。空欄の場合は現在の管理画面ドメインを使います。</div>
        </div>

        <div class="form-group">
          <label>状態</label>
          <select name="status">
            <option value="active" <?= (($tenant['status'] ?? '') === 'active') ? 'selected' : '' ?>>有効</option>
            <option value="suspended" <?= (($tenant['status'] ?? '') === 'suspended') ? 'selected' : '' ?>>停止中</option>
            <option value="archived" <?= (($tenant['status'] ?? '') === 'archived') ? 'selected' : '' ?>>アーカイブ</option>
          </select>
        </div>

        <div class="form-group">
          <label>メモ</label>
          <textarea name="memo" placeholder="導入状況、契約条件、担当者など"><?= htmlspecialchars($tenant['memo'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <?php if (!$isEdit): ?>
          <div style="border-top:1px solid var(--border);margin:18px 0;padding-top:18px">
            <h3 style="font-size:16px;margin:0 0 10px">導入時によく使う初期設定</h3>
            <p style="line-height:1.7;color:var(--muted);margin:0 0 14px">あとから「クライアント別設定」で変更できます。分かる項目だけ入力してください。</p>

            <div class="form-group"><label>LINE公式アカウントID</label><input type="text" name="line_official_id" value="<?= $settingValue($tenantSettings ?? [], 'line_official_id') ?>" placeholder="例: @386ipbjr"></div>
            <div class="form-group"><label>予約カレンダー LIFF ID</label><input type="text" name="liff_id" value="<?= $settingValue($tenantSettings ?? [], 'liff_id') ?>" placeholder="例: 2000000000-xxxxxxxx"></div>
            <div class="form-group"><label>購入ページ LIFF ID</label><input type="text" name="shop_liff_id" value="<?= $settingValue($tenantSettings ?? [], 'shop_liff_id') ?>" placeholder="例: 2000000000-yyyyyyyy"></div>
            <div class="form-group"><label>画像生成 LIFF ID</label><input type="text" name="generate_liff_id" value="<?= $settingValue($tenantSettings ?? [], 'generate_liff_id') ?>" placeholder="例: 2000000000-zzzzzzzz"><small>オンライン生成型では、画像生成専用LIFFアプリのIDを入力します。</small></div>
            <div class="form-group"><label>月額サブスク Price ID</label><input type="text" name="stripe_subscription_price_id" value="<?= $settingValue($tenantSettings ?? [], 'stripe_subscription_price_id') ?>" placeholder="price_..."></div>
            <div class="form-group"><label>年額サブスク Price ID</label><input type="text" name="stripe_annual_subscription_price_id" value="<?= $settingValue($tenantSettings ?? [], 'stripe_annual_subscription_price_id') ?>" placeholder="price_..."></div>
            <div class="form-group"><label>一回払い Price ID</label><input type="text" name="one_time_price_id" value="<?= $settingValue($tenantSettings ?? [], 'one_time_price_id') ?>" placeholder="price_..."></div>
            <div class="form-group"><label>LINE月間送信上限</label><input type="number" name="line_monthly_limit" value="<?= $settingValue($tenantSettings ?? [], 'line_monthly_limit', '5000') ?>" min="0"></div>
            <div class="form-group"><label>1ユーザー1日の最大依頼数</label><input type="number" name="daily_request_limit" value="<?= $settingValue($tenantSettings ?? [], 'daily_request_limit', '2') ?>" min="0"></div>
            <div class="form-group"><label>1依頼あたり最大生成枚数</label><input type="number" name="max_images_per_request" value="<?= $settingValue($tenantSettings ?? [], 'max_images_per_request', '4') ?>" min="1"></div>

            <div class="form-group">
              <label>予約承認方式</label>
              <?php $approvalMode = (string)(($tenantSettings['workflow_approval_mode'] ?? 'manual')); ?>
              <select name="workflow_approval_mode">
                <option value="manual" <?= $approvalMode === 'manual' ? 'selected' : '' ?>>手動承認</option>
                <option value="auto" <?= $approvalMode === 'auto' ? 'selected' : '' ?>>自動承認</option>
                <option value="paid_auto" <?= $approvalMode === 'paid_auto' ? 'selected' : '' ?>>支払い完了後に自動承認</option>
              </select>
            </div>

            <div class="form-group">
              <label>画像生成の参加条件</label>
              <?php $attendanceGate = (string)(($tenantSettings['workflow_attendance_gate'] ?? 'approved_and_time_window')); ?>
              <select name="workflow_attendance_gate">
                <option value="approved_and_time_window" <?= $attendanceGate === 'approved_and_time_window' ? 'selected' : '' ?>>承認済み、かつ参加時間内</option>
                <option value="approved_only" <?= $attendanceGate === 'approved_only' ? 'selected' : '' ?>>予約承認済みなら可能</option>
                <option value="paid_or_free_and_time_window" <?= $attendanceGate === 'paid_or_free_and_time_window' ? 'selected' : '' ?>>支払い条件を満たし、かつ参加時間内</option>
              </select>
            </div>

            <div class="form-group">
              <label>初回無料</label>
            </div>

            <div class="form-group">
              <label>生成申請の受付方式</label>
              <?php $generationAccessMode = (string)(($tenantSettings['generation_access_mode'] ?? 'class_attendance')); ?>
              <select name="generation_access_mode">
                <option value="class_attendance" <?= $generationAccessMode === 'class_attendance' ? 'selected' : '' ?>>教室参加確認後のみ</option>
                <option value="time_window_only" <?= $generationAccessMode === 'time_window_only' ? 'selected' : '' ?>>受付時間内なら生成可能（予約不要）</option>
                <option value="class_or_time_window" <?= $generationAccessMode === 'class_or_time_window' ? 'selected' : '' ?>>参加確認済み、または受付時間内</option>
                <option value="always_open" <?= $generationAccessMode === 'always_open' ? 'selected' : '' ?>>常時受付</option>
              </select>
              <p class="setting-help">教室予約を使わないクライアントは「受付時間内なら生成可能」を選択します。</p>
            </div>

            <div class="settings-two">
              <div class="form-group"><label>生成受付開始</label><input type="time" name="generation_window_start" value="<?= $settingValue($tenantSettings ?? [], 'generation_window_start') ?>"></div>
              <div class="form-group"><label>生成受付終了</label><input type="time" name="generation_window_end" value="<?= $settingValue($tenantSettings ?? [], 'generation_window_end') ?>"></div>
            </div>
            <div class="form-group"><label>受付時間外メッセージ</label><textarea name="generation_window_message" rows="3" placeholder="未入力の場合は標準メッセージを表示します。"><?= $settingValue($tenantSettings ?? [], 'generation_window_message') ?></textarea></div>

            <div class="form-group">
              <label>初回無料</label>
              <?php $firstVisitFree = (string)(($tenantSettings['first_visit_free_enabled'] ?? '1')); ?>
              <select name="first_visit_free_enabled">
                <option value="1" <?= $firstVisitFree === '1' ? 'selected' : '' ?>>有効</option>
                <option value="0" <?= $firstVisitFree === '0' ? 'selected' : '' ?>>無効</option>
              </select>
            </div>
          </div>

          <div style="border-top:1px solid var(--border);margin:18px 0;padding-top:18px">
            <h3 style="font-size:16px;margin:0 0 10px">初期管理者アカウント</h3>
            <p style="line-height:1.7;color:var(--muted);margin:0 0 14px">クライアント担当者を同時に作成できます。不要な場合は空欄のまま保存してください。</p>

            <div class="form-group"><label>名前</label><input type="text" name="initial_admin_name" value="<?= $adminValue($adminSeed ?? [], 'name') ?>" placeholder="例: 田中 太郎"></div>
            <div class="form-group"><label>メールアドレス</label><input type="email" name="initial_admin_email" value="<?= $adminValue($adminSeed ?? [], 'email') ?>" placeholder="client-admin@example.com"></div>
            <div class="form-group">
              <label>権限</label>
              <?php $initialRole = (string)(($adminSeed['role'] ?? 'admin')); ?>
              <select name="initial_admin_role">
                <option value="admin" <?= $initialRole === 'admin' ? 'selected' : '' ?>>管理者</option>
                <option value="staff" <?= $initialRole === 'staff' ? 'selected' : '' ?>>スタッフ</option>
              </select>
            </div>
            <div class="form-group"><label>初期パスワード</label><input type="password" name="initial_admin_password" value="" autocomplete="new-password" placeholder="8文字以上"></div>
          </div>
        <?php endif; ?>

        <button class="btn btn-primary" type="submit">保存</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">導入後に確認すること</div>
    <div class="card-body">
      <ul style="line-height:1.9;color:var(--muted);padding-left:18px">
        <li>LINE公式アカウント、Messaging API、LIFF ID、リッチメニュー</li>
        <li>Stripeの公開可能キー、シークレットキー、Webhook、Price ID</li>
        <li>AI APIキー、生成制限、画像品質設定</li>
        <li>公開ページ、会社情報、利用規約、プライバシーポリシー、特商法</li>
        <li>管理者、スタッフ、運営フロー、通知文</li>
      </ul>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
