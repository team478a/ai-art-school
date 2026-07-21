<?php
$pageTitle = 'ダッシュボード';
ob_start();

$esc = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$short = static function ($value, int $width = 36): string {
    $value = (string)$value;
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $width, '...', 'UTF-8');
    }
    return strlen($value) > $width ? substr($value, 0, $width) . '...' : $value;
};

$stats = array_merge([
    'today_requests' => 0,
    'today_images' => 0,
    'failed_count' => 0,
    'processing_count' => 0,
    'total_requests' => 0,
    'total_images' => 0,
], $stats ?? []);

$monitor = array_merge([
    'worker_alert' => true,
    'worker_last_run' => '',
    'worker_diff_sec' => null,
    'line_push_alert' => false,
    'line_push_count' => 0,
    'line_push_limit' => 0,
    'stability_credits' => '',
    'stability_checked_at' => '',
], $monitor ?? []);

$settings = $settings ?? [];
$engine = strtolower((string)($settings['image_engine'] ?? 'stability'));
$engineLabelMap = [
    'stability' => 'Stability AI',
    'openai' => 'OpenAI',
    'grok' => 'Grok AI',
];
$engineLabel = $engineLabelMap[$engine] ?? $engine;
$creditsError = class_exists('Settings') ? Settings::get('stability_credits_error', '') : '';
$autoSwitchEnabled = (string)($settings['stability_auto_switch_enabled'] ?? '1') !== '0';
$switchThreshold = (string)($settings['stability_auto_switch_threshold'] ?? '1');
$fallbackEngine = strtolower((string)($settings['stability_fallback_engine'] ?? 'openai'));
$fallbackLabel = $engineLabelMap[$fallbackEngine] ?? $fallbackEngine;
$configuredEngines = [
    'stability' => [
        'label' => 'Stability AI',
        'configured' => trim((string)($settings['stability_api_key'] ?? '')) !== '',
    ],
    'openai' => [
        'label' => 'OpenAI',
        'configured' => trim((string)($settings['openai_api_key'] ?? '')) !== '',
    ],
    'grok' => [
        'label' => 'Grok AI',
        'configured' => trim((string)($settings['grok_api_key'] ?? '')) !== '',
    ],
];
?>

<?php if (!empty($_GET['manual_queued'])): ?>
<div class="alert alert-success" style="margin-bottom:16px">
  手動処理を受け付けました。画面を閉じても処理は継続します。
  <?php if (!empty($_GET['request_id'])): ?>
    対象依頼：#<?= number_format((int)$_GET['request_id']) ?>。
  <?php else: ?>
    画像生成待ちはないため、通知処理のみ実行します。
  <?php endif; ?>
  数分後にダッシュボードまたは依頼一覧を更新してください。
</div>
<?php endif; ?>

<?php if (!empty($_GET['manual_cron'])): ?>
<?php if (!empty($_GET['manual_error'])): ?>
<div class="alert alert-error" style="margin-bottom:16px">
  手動処理の一部でエラーが発生しました。依頼詳細または操作ログを確認してください。
</div>
<?php else: ?>
<div class="alert alert-success" style="margin-bottom:16px">
  手動処理が完了しました。
  画像生成 <?= number_format((int)($_GET['image_processed'] ?? 0)) ?>件、
  リマインド <?= number_format((int)($_GET['reminded'] ?? 0)) ?>件、
  空席通知 <?= number_format((int)($_GET['waitlist'] ?? 0)) ?>件、
  フォロー <?= number_format((int)($_GET['followups'] ?? 0)) ?>件。
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ((int)($stats['failed_count'] ?? 0) > 0): ?>
<div class="alert alert-error" style="margin-bottom:16px">
  本日 <?= number_format((int)$stats['failed_count']) ?> 件の生成失敗があります。
  <a href="/admin/image-requests?status=failed" style="color:inherit;text-decoration:underline">失敗した依頼を確認</a>
</div>
<?php endif; ?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">本日の依頼</div>
    <div class="stat-value accent"><?= number_format((int)$stats['today_requests']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">本日の生成枚数</div>
    <div class="stat-value accent"><?= number_format((int)$stats['today_images']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">本日の失敗</div>
    <div class="stat-value <?= (int)$stats['failed_count'] > 0 ? 'danger' : 'success' ?>"><?= number_format((int)$stats['failed_count']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">処理中</div>
    <div class="stat-value warning"><?= number_format((int)$stats['processing_count']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">累計依頼</div>
    <div class="stat-value"><?= number_format((int)$stats['total_requests']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">累計生成枚数</div>
    <div class="stat-value"><?= number_format((int)$stats['total_images']) ?></div>
  </div>
</div>

<div class="dashboard-monitor-grid">
  <div class="card" style="<?= !empty($monitor['worker_alert']) ? 'border-color:var(--danger)' : '' ?>">
    <div class="card-header">自動処理（cron）</div>
    <div class="card-body">
      <?php if (!empty($monitor['worker_alert'])): ?>
        <div class="monitor-status danger">停止の疑い</div>
        <div class="monitor-note">
          <?php if (!empty($monitor['worker_last_run'])): ?>
            最終実行：<?= $esc(date('m/d H:i', strtotime((string)$monitor['worker_last_run']))) ?>
            （<?= number_format((int)floor(((int)$monitor['worker_diff_sec']) / 60)) ?>分前）
          <?php else: ?>
            まだ一度も実行されていません。
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="monitor-status success">正常</div>
        <div class="monitor-note">
          最終実行：<?= $esc(date('H:i:s', strtotime((string)$monitor['worker_last_run']))) ?>
          （<?= number_format((int)$monitor['worker_diff_sec']) ?>秒前）
        </div>
      <?php endif; ?>
      <?php if (class_exists('AdminAuthController') && AdminAuthController::isAdmin()): ?>
        <form method="POST" action="/admin/manual-cron" style="margin-top:14px" onsubmit="return confirm('CRONの代わりに、現在のクライアントの画像生成1件と通知処理を実行します。受付後は画面を閉じても処理を継続します。実行しますか？');">
          <?= function_exists('csrf_field') ? csrf_field() : '' ?>
          <button type="submit" class="btn btn-primary btn-sm">手動処理を実行</button>
        </form>
        <div class="monitor-note">共有サーバーのCRONが停止した場合に使用します。画像生成は1回につき1件です。</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="<?= !empty($monitor['line_push_alert']) ? 'border-color:var(--warning)' : '' ?>">
    <div class="card-header">LINE送信数（今月）</div>
    <div class="card-body">
      <?php $lineLimit = max(0, (int)$monitor['line_push_limit']); ?>
      <?php $lineCount = max(0, (int)$monitor['line_push_count']); ?>
      <?php $pct = $lineLimit > 0 ? min(100, round($lineCount / $lineLimit * 100)) : 0; ?>
      <div class="line-count <?= !empty($monitor['line_push_alert']) ? 'warning' : '' ?>">
        <?= number_format($lineCount) ?>
        <span>/ <?= number_format($lineLimit) ?>通</span>
      </div>
      <div class="progress"><div style="width:<?= (int)$pct ?>%"></div></div>
      <div class="monitor-note">残り約 <?= number_format(max(0, $lineLimit - $lineCount)) ?> 通</div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">画像生成エンジン</div>
    <div class="card-body">
      <div class="current-engine">現在：<strong><?= $esc($engineLabel) ?></strong></div>
      <div class="engine-badges">
        <?php foreach ($configuredEngines as $engineKey => $engineInfo): ?>
          <?php
            $isActiveEngine = $engineKey === $engine;
            $isConfigured = !empty($engineInfo['configured']);
            $class = $isActiveEngine ? 'active' : ($isConfigured ? 'ready' : 'missing');
          ?>
          <span class="engine-badge <?= $class ?>">
            <?= $esc($engineInfo['label']) ?><?= $isActiveEngine ? ' 使用中' : ($isConfigured ? ' 設定済み' : ' 未設定') ?>
          </span>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($configuredEngines['stability']['configured'])): ?>
        <?php if ($monitor['stability_credits'] !== '' && is_numeric($monitor['stability_credits'])): ?>
          <?php $cr = (float)$monitor['stability_credits']; ?>
          <div class="credits <?= $cr <= (float)$switchThreshold ? 'danger' : 'success' ?>">
            <?= number_format($cr, 1) ?><span> クレジット</span>
          </div>
        <?php else: ?>
          <div class="monitor-note">残高未取得</div>
        <?php endif; ?>

        <?php if (!empty($monitor['stability_checked_at'])): ?>
          <div class="monitor-note">最終更新：<?= $esc($monitor['stability_checked_at']) ?></div>
        <?php endif; ?>

        <?php if ($creditsError !== ''): ?>
          <div class="credits-error"><?= $esc($creditsError) ?></div>
        <?php endif; ?>

        <form id="stabilityCreditsForm" method="post" action="/admin/stability-credits" style="display:none">
          <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        </form>
        <div id="creditsRefreshMessage" class="refresh-message"></div>
        <button onclick="refreshCredits(this)" class="btn btn-secondary btn-sm" style="margin-top:10px">残高を更新</button>
      <?php else: ?>
        <div class="monitor-note">Stability AI APIキーが未設定のため、残高は確認できません。</div>
      <?php endif; ?>

      <div class="monitor-note auto-switch">
        自動切替：<?= $autoSwitchEnabled ? '有効' : '無効' ?>
        <?php if ($autoSwitchEnabled): ?>
          （<?= $esc($switchThreshold) ?>クレジット以下で <?= $esc($fallbackLabel) ?> へ切替）
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">最近の依頼</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>ユーザー</th>
          <th>入力</th>
          <th>ステータス</th>
          <th>日時</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (($recent ?? []) as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= $esc($row['display_name'] ?? '-') ?></td>
            <td><?= $esc($short($row['input_text'] ?? '', 42)) ?></td>
            <td><?= $esc($row['status'] ?? '') ?></td>
            <td><?= $esc(!empty($row['created_at']) ? date('m/d H:i', strtotime((string)$row['created_at'])) : '-') ?></td>
            <td><a class="btn btn-secondary btn-sm" href="/admin/image-requests/<?= (int)$row['id'] ?>">詳細</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">依頼はまだありません。</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function refreshCredits(btn) {
  const box = document.getElementById('creditsRefreshMessage');
  const original = btn.textContent;
  btn.disabled = true;
  btn.textContent = '取得中...';
  if (box) {
    box.className = 'refresh-message';
    box.textContent = 'Stability AIの残高を確認しています...';
  }

  const form = document.getElementById('stabilityCreditsForm');
  const body = form ? new FormData(form) : new FormData();

  fetch('/admin/stability-credits', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' },
    body
  })
    .then(async response => {
      const text = await response.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch (e) {
        throw new Error('JSONではない応答です。ログイン状態、権限、またはサーバーエラーを確認してください。');
      }
      if (!response.ok || !data.ok) {
        throw new Error(data.message || '残高更新に失敗しました。');
      }
      if (box) {
        box.className = 'refresh-message success';
        box.textContent = data.message || '残高を更新しました。';
      }
      setTimeout(() => location.reload(), 700);
    })
    .catch(error => {
      if (box) {
        box.className = 'refresh-message error';
        box.textContent = error.message || '通信エラーが発生しました。';
      }
    })
    .finally(() => {
      btn.disabled = false;
      btn.textContent = original;
    });
}
</script>

<style>
.dashboard-monitor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px}
.monitor-status{font-size:24px;font-weight:800}
.monitor-status.success{color:var(--success)}
.monitor-status.danger{color:var(--danger)}
.monitor-note{font-size:12px;color:var(--muted);margin-top:6px}
.line-count{font-size:24px;font-weight:800;color:var(--accent2)}
.line-count.warning{color:var(--warning)}
.line-count span{font-size:13px;color:var(--muted);font-weight:400}
.progress{background:var(--bg);border-radius:6px;height:6px;margin-top:10px;overflow:hidden}
.progress div{height:100%;background:var(--accent)}
.engine-badges{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0}
.engine-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:11px;border:1px solid var(--border);background:var(--bg);color:var(--muted)}
.engine-badge.ready{background:rgba(34,197,94,.12);color:var(--success)}
.engine-badge.active{background:var(--accent);color:#fff}
.current-engine{font-size:13px;margin-bottom:8px}
.credits{font-size:24px;font-weight:800;margin-top:4px}
.credits.success{color:var(--success)}
.credits.danger{color:var(--danger)}
.credits span{font-size:12px;color:var(--muted);font-weight:400}
.credits-error,.refresh-message.error{font-size:12px;color:var(--danger);margin-top:8px}
.refresh-message.success{font-size:12px;color:var(--success);margin-top:8px}
.auto-switch{margin-top:10px}
@media (max-width: 900px){.dashboard-monitor-grid{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
