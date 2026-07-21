<?php
$pageTitle = 'クライアントバックアップ';
ob_start();

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$sizeText = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
};
?>

<?php if (!empty($saved)): ?>
  <div class="alert alert-success">
    <?php if ($saved === 'created'): ?>
      バックアップを作成しました。
    <?php elseif ($saved === 'restored'): ?>
      バックアップから復元しました。
      <?php if (!empty($restoreSummary)): ?>
        復元: <?= (int)($restoreSummary['tables'] ?? 0) ?>テーブル /
        <?= (int)($restoreSummary['rows'] ?? 0) ?>行 /
        <?= (int)($restoreSummary['assets'] ?? 0) ?>ファイル
      <?php endif; ?>
    <?php else: ?>
      処理が完了しました。
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= $h($error) ?></div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-secondary" href="/admin/tenants/<?= (int)$tenant['id'] ?>/settings">← 設定へ戻る</a>
    <a class="btn btn-secondary" href="/admin/tenants">クライアント一覧</a>
  </div>
  <div style="color:var(--muted)">
    対象: <strong><?= $h($tenant['name'] ?? '') ?></strong>
    <code><?= $h($tenant['tenant_key'] ?? '') ?></code>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header">この画面でできること</div>
  <div class="card-body">
    <p style="line-height:1.8;color:var(--muted)">
      このクライアントだけの設定、予約、参加者、画像生成履歴、決済ログ、ガチャ関連データをZIPとして保存します。
      見つかったアップロード画像などの参照ファイルも、可能な範囲で一緒に含めます。
    </p>
    <p style="line-height:1.8;color:var(--muted);margin-top:8px">
      復元は同じクライアントキーのバックアップだけ実行できます。他クライアントのデータには触れません。
      復元すると、このクライアントの対象データはバックアップ時点の内容で置き換わります。
    </p>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header">新しいバックアップを作成</div>
  <div class="card-body">
    <form method="post" action="/admin/tenants/<?= (int)$tenant['id'] ?>/backups">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit">今すぐバックアップを作成</button>
    </form>
    <p style="line-height:1.8;color:var(--muted);margin-top:12px">
      保存先: <code>storage/tenant_backups/<?= $h($tenant['tenant_key'] ?? '') ?></code>
    </p>
  </div>
</div>

<div class="card">
  <div class="card-header">バックアップ一覧</div>
  <div class="card-body">
    <?php if (empty($backups)): ?>
      <p style="color:var(--muted)">まだバックアップはありません。</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>バックアップID</th>
              <th>作成日時</th>
              <th>サイズ</th>
              <th>テーブル</th>
              <th>行数</th>
              <th>ファイル</th>
              <th>作成時バージョン</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($backups as $backup): ?>
              <tr>
                <td><code><?= $h($backup['id'] ?? '') ?></code></td>
                <td><?= $h($backup['created_at'] ?? '') ?></td>
                <td><?= $h($sizeText((int)($backup['size'] ?? 0))) ?></td>
                <td><?= (int)($backup['tables'] ?? 0) ?></td>
                <td><?= (int)($backup['rows'] ?? 0) ?></td>
                <td><?= (int)($backup['assets'] ?? 0) ?></td>
                <td><?= $h($backup['version'] ?? '') ?></td>
                <td>
                  <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <a class="btn btn-secondary" href="/admin/tenants/<?= (int)$tenant['id'] ?>/backups/<?= rawurlencode((string)($backup['id'] ?? '')) ?>/download">ダウンロード</a>
                    <form method="post" action="/admin/tenants/<?= (int)$tenant['id'] ?>/backups/restore" onsubmit="return confirm('このクライアントの対象データをバックアップ時点に復元します。実行しますか？')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="backup_id" value="<?= $h($backup['id'] ?? '') ?>">
                      <button class="btn btn-danger" type="submit">復元</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
