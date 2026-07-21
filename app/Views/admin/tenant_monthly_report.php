<?php
$pageTitle = '月次利用・請求レポート';
ob_start();

$money = static function ($value): string {
    return '¥' . number_format((int)$value);
};
$num = static function ($value): string {
    return number_format((int)$value);
};
$month = (string)($report['month'] ?? date('Y-m'));
$rows = $report['rows'] ?? [];
$totals = $report['totals'] ?? [];
?>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-secondary" href="/admin/tenants">← クライアント管理へ戻る</a>
    <a class="btn btn-secondary" href="/admin/tenants/monthly-report?month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>">← 前月</a>
    <form method="get" action="/admin/tenants/monthly-report" style="display:flex;gap:8px;align-items:center">
      <input type="month" name="month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>" style="max-width:160px">
      <button class="btn btn-primary" type="submit">表示</button>
    </form>
    <a class="btn btn-secondary" href="/admin/tenants/monthly-report?month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>">翌月 →</a>
    <a class="btn btn-primary" href="/admin/tenants/monthly-report?month=<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>&format=csv">CSV出力</a>
  </div>
  <div style="color:var(--muted)">対象月: <strong><?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?></strong></div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header">この画面で確認できること</div>
  <div class="card-body">
    <p style="line-height:1.8;color:var(--muted)">
      クライアントごとの月次売上、返金、予約、参加、画像生成、LINE送信数を一覧で確認できます。
      SaaS運用では、請求確認、サポート優先度、利用状況の把握に使います。
    </p>
    <p style="line-height:1.8;color:var(--muted);margin-top:8px">
      金額はシステム内の決済ログから集計します。Stripeダッシュボードの確定売上と差がある場合は、
      Webhook未反映、返金処理、手動入金、テーブル項目名の違いを確認してください。
    </p>
  </div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px">
  <div class="card"><div class="card-body"><div style="color:var(--muted)">売上</div><div style="font-size:30px;font-weight:900;color:var(--success)"><?= $money($totals['payment_amount'] ?? 0) ?></div></div></div>
  <div class="card"><div class="card-body"><div style="color:var(--muted)">返金</div><div style="font-size:30px;font-weight:900;color:var(--danger)"><?= $money($totals['refund_amount'] ?? 0) ?></div></div></div>
  <div class="card"><div class="card-body"><div style="color:var(--muted)">差引</div><div style="font-size:30px;font-weight:900"><?= $money($totals['net_amount'] ?? 0) ?></div></div></div>
  <div class="card"><div class="card-body"><div style="color:var(--muted)">画像生成</div><div style="font-size:30px;font-weight:900;color:var(--accent2)"><?= $num($totals['completed_images'] ?? 0) ?></div></div></div>
  <div class="card"><div class="card-body"><div style="color:var(--muted)">LINE送信</div><div style="font-size:30px;font-weight:900;color:var(--accent2)"><?= $num($totals['line_messages'] ?? 0) ?></div></div></div>
</div>

<div class="card">
  <div class="card-header">クライアント別 月次集計</div>
  <div class="card-body">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>クライアント</th>
            <th>売上</th>
            <th>返金</th>
            <th>差引</th>
            <th>決済</th>
            <th>予約</th>
            <th>承認</th>
            <th>参加</th>
            <th>画像生成</th>
            <th>失敗</th>
            <th>LINE</th>
            <th>確認</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $tenant = $row['tenant'] ?? []; ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($tenant['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                <div style="color:var(--muted);font-size:12px;margin-top:4px">
                  <code><?= htmlspecialchars($tenant['tenant_key'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
                </div>
              </td>
              <td style="font-weight:800;color:var(--success)"><?= $money($row['payment_amount'] ?? 0) ?></td>
              <td style="color:var(--danger)"><?= $money($row['refund_amount'] ?? 0) ?></td>
              <td style="font-weight:800"><?= $money($row['net_amount'] ?? 0) ?></td>
              <td><?= $num($row['payments'] ?? 0) ?>件</td>
              <td><?= $num($row['reservations'] ?? 0) ?>件</td>
              <td><?= $num($row['approved'] ?? 0) ?>件</td>
              <td><?= $num($row['attended'] ?? 0) ?>件</td>
              <td><?= $num($row['completed_images'] ?? 0) ?>件</td>
              <td>
                <?php if ((int)($row['failed_images'] ?? 0) > 0): ?>
                  <span style="color:var(--danger);font-weight:800"><?= $num($row['failed_images']) ?>件</span>
                <?php else: ?>
                  0件
                <?php endif; ?>
              </td>
              <td><?= $num($row['line_messages'] ?? 0) ?>通</td>
              <td>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <a class="btn btn-secondary" href="/admin/tenants/<?= (int)($tenant['id'] ?? 0) ?>/settings">設定</a>
                  <a class="btn btn-secondary" href="/admin/tenants/<?= (int)($tenant['id'] ?? 0) ?>/diagnostics">診断</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="12" style="text-align:center;color:var(--muted)">クライアントがまだありません。</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <th>合計</th>
            <th><?= $money($totals['payment_amount'] ?? 0) ?></th>
            <th><?= $money($totals['refund_amount'] ?? 0) ?></th>
            <th><?= $money($totals['net_amount'] ?? 0) ?></th>
            <th><?= $num($totals['payments'] ?? 0) ?>件</th>
            <th><?= $num($totals['reservations'] ?? 0) ?>件</th>
            <th><?= $num($totals['approved'] ?? 0) ?>件</th>
            <th><?= $num($totals['attended'] ?? 0) ?>件</th>
            <th><?= $num($totals['completed_images'] ?? 0) ?>件</th>
            <th><?= $num($totals['failed_images'] ?? 0) ?>件</th>
            <th><?= $num($totals['line_messages'] ?? 0) ?>通</th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
