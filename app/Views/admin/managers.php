<?php
$pageTitle = '管理者アカウント';
$savedMessages = [
    'created' => '管理者アカウントを追加しました。',
    'staff_created' => 'スタッフアカウントを追加しました。',
    'role' => '権限を更新しました。',
    'tenant' => '所属クライアントを更新しました。',
    'status' => '状態を更新しました。',
    'password' => 'パスワードを更新しました。',
    'deleted' => 'アカウントを削除しました。',
];
$roleLabels = [
    'owner' => 'オーナー',
    'admin' => '管理者',
    'staff' => 'スタッフ',
];
$roleHelp = [
    'owner' => '全クライアントとシステム設定を管理できます。API設定、LINE設定、公開ページ設定、アップデート、操作ログ、管理者アカウントを含むすべての操作ができます。',
    'admin' => '担当クライアントの教室運営を広く操作できます。教室、予約、ユーザー、決済、チケット、統計、配信、ギャラリーを扱えます。システム設定は操作できません。',
    'staff' => '当日運営向けの権限です。カレンダー、教室、予約、出席、QRコード、使い方マニュアルを中心に操作できます。システム設定は表示・操作できません。',
];
ob_start();
?>

<?php if (!empty($saved) && isset($savedMessages[$saved])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($savedMessages[$saved], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="responsive-grid" style="grid-template-columns:minmax(0,1fr) 380px;align-items:start">
  <div class="card">
    <div class="card-header">アカウント一覧</div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>名前</th>
              <th>メールアドレス</th>
              <th>権限</th>
              <th>所属クライアント</th>
              <th>状態</th>
              <th>最終ログイン</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (($admins ?? []) as $admin): ?>
              <?php
                $role = $admin['role'] ?? 'staff';
                $status = $admin['status'] ?? 'active';
                $isSelf = (int)($admin['id'] ?? 0) === (int)($_SESSION['admin_id'] ?? 0);
              ?>
              <tr>
                <td><?= htmlspecialchars($admin['name'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($admin['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <form method="POST" action="/admin/managers/<?= (int)$admin['id'] ?>/role">
                    <?= csrf_field() ?>
                    <select name="role" onchange="this.form.submit()">
                      <?php foreach ($roleLabels as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $role === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td>
                  <?php if (in_array($role, ['owner', 'super_owner'], true)): ?>
                    <span style="color:var(--muted)">全クライアント</span>
                  <?php else: ?>
                    <form method="POST" action="/admin/managers/<?= (int)$admin['id'] ?>/tenant">
                      <?= csrf_field() ?>
                      <select name="tenant_id" onchange="this.form.submit()">
                        <option value="">未設定</option>
                        <?php foreach (($tenants ?? []) as $tenant): ?>
                          <option value="<?= (int)$tenant['id'] ?>" <?= (int)($admin['tenant_id'] ?? 0) === (int)$tenant['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tenant['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <?php if (!empty($admin['tenant_key'])): ?>
                        <div style="color:var(--muted);font-size:11px;margin-top:4px"><?= htmlspecialchars($admin['tenant_key'], ENT_QUOTES, 'UTF-8') ?></div>
                      <?php endif; ?>
                    </form>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="POST" action="/admin/managers/<?= (int)$admin['id'] ?>/status">
                    <?= csrf_field() ?>
                    <select name="status" onchange="this.form.submit()" <?= $isSelf ? 'disabled' : '' ?>>
                      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>有効</option>
                      <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>停止</option>
                    </select>
                  </form>
                </td>
                <td><?= htmlspecialchars($admin['last_login_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td style="min-width:240px">
                  <form method="POST" action="/admin/managers/<?= (int)$admin['id'] ?>/password" style="display:flex;gap:6px;margin-bottom:8px">
                    <?= csrf_field() ?>
                    <input type="password" name="password" placeholder="新しいパスワード" minlength="8" required>
                    <button class="btn btn-secondary" type="submit">変更</button>
                  </form>
                  <form method="POST" action="/admin/managers/<?= (int)$admin['id'] ?>/delete" onsubmit="return confirm('このアカウントを削除しますか？')">
                    <?= csrf_field() ?>
                    <button class="btn btn-danger" type="submit" <?= $isSelf ? 'disabled' : '' ?>>削除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($admins)): ?>
              <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px">アカウントはまだありません。</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-header">スタッフを追加</div>
      <div class="card-body">
        <form method="POST" action="/admin/managers">
          <?= csrf_field() ?>
          <div class="form-group">
            <label>名前</label>
            <input type="text" name="name" placeholder="例：山田 太郎">
          </div>
          <div class="form-group">
            <label>メールアドレス</label>
            <input type="email" name="email" required>
          </div>
          <div class="form-group">
            <label>権限</label>
            <select name="role">
              <option value="staff" selected>スタッフ</option>
              <option value="admin">管理者</option>
              <option value="owner">オーナー</option>
            </select>
          </div>
          <div class="form-group">
            <label>所属クライアント</label>
            <select name="tenant_id">
              <option value="">未設定</option>
              <?php foreach (($tenants ?? []) as $tenant): ?>
                <option value="<?= (int)$tenant['id'] ?>"><?= htmlspecialchars($tenant['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
            <div style="color:var(--muted);font-size:12px;margin-top:6px">管理者・スタッフは担当クライアントを設定すると、ログイン時にそのクライアントの管理画面として動作します。</div>
          </div>
          <div class="form-group">
            <label>初期パスワード</label>
            <input type="password" name="password" minlength="8" required>
            <div style="color:var(--muted);font-size:12px;margin-top:6px">8文字以上で設定してください。追加後、本人にログイン情報を共有してください。</div>
          </div>
          <button class="btn btn-primary" type="submit">追加する</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">権限の目安</div>
      <div class="card-body">
        <?php foreach ($roleHelp as $value => $text): ?>
          <div style="margin-bottom:14px">
            <strong><?= htmlspecialchars($roleLabels[$value], ENT_QUOTES, 'UTF-8') ?></strong>
            <div style="color:var(--muted);font-size:12px;margin-top:4px;line-height:1.6"><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
