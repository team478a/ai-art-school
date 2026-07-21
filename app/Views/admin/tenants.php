<?php
$pageTitle = 'クライアント管理';
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$shortText = static function ($value, int $width = 90): string {
    $text = (string)$value;
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $width, '...', 'UTF-8');
    }
    return strlen($text) > $width ? substr($text, 0, $width) . '...' : $text;
};
$statusLabel = static function (string $status): string {
    return ['active' => '有効', 'suspended' => '停止中', 'archived' => 'アーカイブ'][$status] ?? '未確認';
};
$statusColor = static function (string $status): string {
    return $status === 'active' ? 'var(--success)' : ($status === 'archived' ? 'var(--muted)' : 'var(--warning)');
};
$diagStatusLabel = static function (array $diag): string {
    if (!empty($diag['ok'])) {
        return 'OK';
    }
    if (($diag['status'] ?? '') === 'not_used') {
        return '未使用';
    }
    return '要確認';
};
$diagStatusColor = static function (array $diag): string {
    if (!empty($diag['ok'])) {
        return 'var(--success)';
    }
    if (($diag['status'] ?? '') === 'not_used') {
        return 'var(--muted)';
    }
    return 'var(--danger)';
};
ob_start();
?>

<?php if (!empty($saved)): ?>
  <div class="alert alert-success">クライアント情報を保存しました。</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= $h($error) ?></div>
<?php endif; ?>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
  <a class="btn btn-primary" href="/admin/tenants/create">+ クライアントを追加</a>
  <a class="btn btn-secondary" href="/admin/tenants/monthly-report">月次利用・請求レポート</a>
  <a class="btn btn-secondary" href="/admin/client-setup">横展開・初期設定</a>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header">SaaS管理の見方</div>
  <div class="card-body">
    <p style="line-height:1.8;color:var(--muted)">
      複数クライアントを1つの管理画面から確認します。LINE、LIFF、Stripe、AI API、公開ページ情報はクライアントごとに分けて管理します。
      標準アカウントは既存システム用の受け皿で、API設定、LINE設定、公開ページ設定などの共通設定で管理します。
    </p>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header">クライアント一覧</div>
  <div class="card-body">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>クライアント</th>
            <th>サービス</th>
            <th>ドメイン</th>
            <th>状態</th>
            <th>現在操作</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (($tenants ?? []) as $tenant): ?>
            <?php
              $tenantId = (int)($tenant['id'] ?? 0);
              $status = (string)($tenant['status'] ?? 'active');
              $isCurrent = !empty($currentTenant) && (int)($currentTenant['id'] ?? 0) === $tenantId;
            ?>
            <tr>
              <td>
                <strong><?= $h($tenant['name'] ?? '') ?></strong>
                <div style="color:var(--muted);font-size:12px;margin-top:4px">
                  キー: <code><?= $h($tenant['tenant_key'] ?? '') ?></code>
                  <?php if (!empty($tenant['is_default'])): ?><span class="badge" style="margin-left:6px">標準</span><?php endif; ?>
                </div>
              </td>
              <td><?= $h($tenant['service_name'] ?? '-') ?></td>
              <td><?= $h($tenant['primary_domain'] ?? '-') ?></td>
              <td><span style="font-weight:800;color:<?= $statusColor($status) ?>"><?= $h($statusLabel($status)) ?></span></td>
              <td><?= $isCurrent ? '<span style="color:var(--accent);font-weight:800">選択中</span>' : '<span style="color:var(--muted)">-</span>' ?></td>
              <td>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                  <a class="btn btn-secondary" href="/admin/tenants/<?= $tenantId ?>/settings">設定</a>
                  <a class="btn btn-secondary" href="/admin/tenants/<?= $tenantId ?>/diagnostics">診断</a>
                  <a class="btn btn-secondary" href="/admin/tenants/<?= $tenantId ?>/backups">バックアップ</a>
                  <a class="btn btn-secondary" href="/admin/tenants/<?= $tenantId ?>/edit">編集</a>
                  <?php if (!$isCurrent && $status === 'active'): ?>
                    <form method="post" action="/admin/tenants/<?= $tenantId ?>/switch">
                      <?= csrf_field() ?>
                      <button class="btn btn-primary" type="submit">このクライアントで操作</button>
                    </form>
                  <?php endif; ?>
                  <?php if (empty($tenant['is_default'])): ?>
                    <?php if ($status !== 'active'): ?>
                      <form method="post" action="/admin/tenants/<?= $tenantId ?>/status">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="active">
                        <button class="btn btn-secondary" type="submit">有効化</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($status === 'active'): ?>
                      <form method="post" action="/admin/tenants/<?= $tenantId ?>/status" onsubmit="return confirm('このクライアントを停止しますか？ログインや公開対象から外れます。');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="suspended">
                        <button class="btn btn-danger" type="submit">停止</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($status !== 'archived'): ?>
                      <form method="post" action="/admin/tenants/<?= $tenantId ?>/status" onsubmit="return confirm('このクライアントをアーカイブしますか？データは削除されません。');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="archived">
                        <button class="btn btn-danger" type="submit">アーカイブ</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($tenants)): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--muted)">クライアントがまだありません。</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!empty($tenantSummaries)): ?>
  <div class="card" style="margin-bottom:18px">
    <div class="card-header">クライアント別 運用サマリー</div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>クライアント</th>
              <th>ユーザー</th>
              <th>開催</th>
              <th>予約</th>
              <th>画像生成</th>
              <th>決済</th>
              <th>LINE</th>
              <th>最新活動</th>
              <th>確認事項</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (($tenants ?? []) as $tenant): ?>
              <?php
                $tenantId = (int)($tenant['id'] ?? 0);
                $summary = $tenantSummaries[$tenantId] ?? [];
                $warnings = $summary['warnings'] ?? [];
              ?>
              <tr>
                <td><strong><?= $h($tenant['name'] ?? '') ?></strong><div style="color:var(--muted);font-size:12px"><?= $h($tenant['tenant_key'] ?? '') ?></div></td>
                <td><?= (int)($summary['users'] ?? 0) ?>人</td>
                <td><?= (int)($summary['future_classes'] ?? 0) ?>件予定<div style="color:var(--muted);font-size:12px">累計 <?= (int)($summary['classes'] ?? 0) ?>件</div></td>
                <td><?= (int)($summary['approved'] ?? 0) ?>件承認<div style="color:var(--muted);font-size:12px">累計 <?= (int)($summary['reservations'] ?? 0) ?>件</div></td>
                <td><?= (int)($summary['completed_images'] ?? 0) ?>件完了<div style="color:<?= (int)($summary['failed_images'] ?? 0) > 0 ? 'var(--danger)' : 'var(--muted)' ?>;font-size:12px">失敗 <?= (int)($summary['failed_images'] ?? 0) ?>件</div></td>
                <td><?= (int)($summary['payments'] ?? 0) ?>件</td>
                <td><?= (int)($summary['line_messages'] ?? 0) ?>通</td>
                <td><?= $h($summary['latest_activity'] ?? '-') ?></td>
                <td>
                  <?php if (empty($warnings)): ?>
                    <span style="color:var(--success);font-weight:700">OK</span>
                  <?php else: ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                      <?php foreach ($warnings as $warning): ?>
                        <span class="badge" style="background:rgba(255,193,7,.18);color:#9a6b00;padding:4px 8px;border-radius:999px"><?= $h($warning) ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($tenantErrorSummaries)): ?>
  <div class="card" style="margin-bottom:18px">
    <div class="card-header">クライアント別 障害監視</div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead><tr><th>クライアント</th><th>状態</th><th>24時間</th><th>7日間</th><th>最新エラー</th><th>確認</th></tr></thead>
          <tbody>
            <?php foreach (($tenants ?? []) as $tenant): ?>
              <?php
                $tenantId = (int)($tenant['id'] ?? 0);
                $monitor = $tenantErrorSummaries[$tenantId] ?? [];
                $level = (string)($monitor['level'] ?? 'ok');
                $latest = $monitor['latest'] ?? null;
                $levelText = $level === 'danger' ? '要対応' : ($level === 'warning' ? '注意' : '正常');
                $levelColor = $level === 'danger' ? 'var(--danger)' : ($level === 'warning' ? 'var(--warning)' : 'var(--success)');
              ?>
              <tr>
                <td><strong><?= $h($tenant['name'] ?? '') ?></strong><div style="color:var(--muted);font-size:12px"><?= $h($tenant['tenant_key'] ?? '') ?></div></td>
                <td><span style="color:<?= $levelColor ?>;font-weight:800"><?= $h($levelText) ?></span></td>
                <td><?= (int)($monitor['last24h'] ?? 0) ?>件</td>
                <td><?= (int)($monitor['last7d'] ?? 0) ?>件</td>
                <td><?= $latest ? $h($shortText($latest['message'] ?? '')) : '<span style="color:var(--muted)">直近エラーなし</span>' ?></td>
                <td><?= $level === 'ok' ? '<span style="color:var(--success);font-weight:700">OK</span>' : '<a class="btn btn-secondary" href="/admin/logs">操作ログを見る</a>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($tenantSetupSummaries)): ?>
  <div class="card" style="margin-bottom:18px">
    <div class="card-header">クライアント別 初期設定チェック</div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead><tr><th>クライアント</th><th>完了率</th><th>次の操作</th></tr></thead>
          <tbody>
            <?php foreach (($tenants ?? []) as $tenant): ?>
              <?php $tenantId = (int)($tenant['id'] ?? 0); $setup = $tenantSetupSummaries[$tenantId] ?? []; ?>
              <tr>
                <td><strong><?= $h($tenant['name'] ?? '') ?></strong><div style="color:var(--muted);font-size:12px"><?= $h($tenant['tenant_key'] ?? '') ?></div></td>
                <?php if (!empty($setup['default_managed'])): ?>
                  <td><span style="color:var(--success);font-weight:800">共通設定で管理</span><div style="color:var(--muted);font-size:12px"><?= $h($setup['message'] ?? '') ?></div></td>
                  <td><a class="btn btn-secondary" href="/admin/settings">API設定を確認</a></td>
                <?php else: ?>
                  <td><strong><?= (int)($setup['score'] ?? 0) ?>%</strong><div style="color:var(--muted);font-size:12px"><?= (int)($setup['completed'] ?? 0) ?> / <?= (int)($setup['total'] ?? 0) ?> 完了</div></td>
                  <td><a class="btn btn-primary" href="/admin/tenants/<?= $tenantId ?>/settings">設定を確認</a></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($dataDiagnostics)): ?>
  <div class="card">
    <div class="card-header">データ分離の準備状況</div>
    <div class="card-body">
      <p style="line-height:1.8;color:var(--muted);margin-top:0">
        標準アカウントでは既存データを標準テナントへ紐づけるため、未割当が0件であれば正常です。
        新規クライアント用の設定とは別扱いです。
      </p>
      <div class="table-wrap">
        <table>
          <thead><tr><th>テーブル</th><th>状態</th><th>tenant_id</th><th>索引</th><th>未割当</th><th>メモ</th></tr></thead>
          <tbody>
            <?php foreach ($dataDiagnostics as $diag): ?>
              <tr>
                <td><code><?= $h($diag['table'] ?? '') ?></code></td>
                <td><span style="color:<?= $diagStatusColor($diag) ?>;font-weight:800"><?= $h($diagStatusLabel($diag)) ?></span></td>
                <td><?= !empty($diag['has_tenant_id']) ? 'あり' : 'なし' ?></td>
                <td><?= !empty($diag['has_index']) ? 'あり' : 'なし' ?></td>
                <td><?= (int)($diag['unassigned'] ?? $diag['unassigned_count'] ?? 0) ?>件</td>
                <td><?= $h($diag['message'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
