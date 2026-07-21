<?php
$pageTitle = '依頼詳細 #' . (int)$request['id'];
ob_start();

$promptMap = [];
foreach ($prompts as $prompt) {
    $promptMap[(string)$prompt['prompt_type']] = $prompt;
}

$imageGroups = [];
foreach ($images as $image) {
    $type = (string)($image['prompt_type'] ?? 'image');
    if (!isset($imageGroups[$type])) {
        $imageGroups[$type] = [];
    }
    $imageGroups[$type][] = $image;
}

$status = (string)($request['status'] ?? '');
?>

<?php if (!empty($_GET['resent'])): ?>
<?php if ((string)$_GET['resent'] === 'link'): ?>
<div class="alert alert-success" style="margin-bottom:16px">LINEの画像送信が通らなかったため、画像URLをテキストで再送しました。</div>
<?php else: ?>
<div class="alert alert-success" style="margin-bottom:16px">生成済み画像をLINEに再送しました。</div>
<?php endif; ?>
<?php endif; ?>
<?php if (!empty($_GET['resend_error'])): ?>
<?php
$resendMessages = [
    'no_line_user' => 'LINEユーザーIDがないため再送できません。',
    'no_images' => '再送できる生成済み画像がありません。',
    'failed' => '再送に失敗しました。LINEブロック、アクセストークン、または対象ユーザーが友だち解除していないか確認してください。',
    'not_found' => '依頼が見つかりません。',
];
$resendMessage = $resendMessages[(string)$_GET['resend_error']] ?? '再送できませんでした。';
?>
<div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($resendMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($_GET['manual_processed'])): ?>
<?php $manualStatus = (string)($_GET['manual_status'] ?? ''); ?>
<div class="alert alert-success" style="margin-bottom:16px">
  手動処理を実行しました。現在の状態：<?= htmlspecialchars($manualStatus !== '' ? $manualStatus : '確認中', ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>
<?php if (!empty($_GET['manual_queued'])): ?>
<div class="alert alert-success" style="margin-bottom:16px">
  手動処理を受け付けました。画面を閉じても処理は継続します。数分後に状態を再確認してください。
</div>
<script>
window.setTimeout(function () {
  window.location.href = '/admin/image-requests/<?= (int)$request['id'] ?>';
}, 10000);
</script>
<?php endif; ?>
<?php if (!empty($_GET['manual_error'])): ?>
<?php
$manualMessages = [
    'not_found' => '依頼が見つかりません。',
    'invalid_status' => '完了済みまたはキャンセル済みの依頼は手動処理できません。',
    'no_job' => '処理できるキューが見つかりませんでした。',
    'queue' => '手動処理の開始準備に失敗しました。すでに処理中でないか、操作ログを確認してください。',
    'failed' => '手動処理に失敗しました。操作ログとAPI設定を確認してください。',
];
$manualMessage = $manualMessages[(string)$_GET['manual_error']] ?? '手動処理を実行できませんでした。';
?>
<div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($manualMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:16px;align-items:center;flex-wrap:wrap">
  <a href="/admin/image-requests" class="btn btn-secondary btn-sm">← 一覧</a>

  <?php if (!in_array($status, ['completed', 'canceled'], true)): ?>
  <form method="POST" action="/admin/image-requests/<?= (int)$request['id'] ?>/process-now" style="display:inline" onsubmit="return confirm('この依頼を今すぐ処理します。途中状態や失敗状態から再開する場合、画像生成APIの利用が再度発生することがあります。受付後は画面を閉じても処理を継続します。実行しますか？');">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary btn-sm">今すぐ手動処理</button>
  </form>
  <?php endif; ?>

  <?php if (in_array($status, ['failed', 'completed'], true)): ?>
  <form method="POST" action="/admin/image-requests/<?= (int)$request['id'] ?>/retry" style="display:inline" onsubmit="return confirm('この依頼を再生成します。画像生成回数やAPI利用が発生する場合があります。よろしいですか？');">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-sm" style="background:rgba(245,158,11,.18);color:var(--warning);border:1px solid rgba(245,158,11,.35)">再生成</button>
  </form>
  <?php endif; ?>

  <?php if ($status === 'completed' && !empty($images)): ?>
  <form method="POST" action="/admin/image-requests/<?= (int)$request['id'] ?>/resend" style="display:inline" onsubmit="return confirm('生成済み画像をLINEに再送します。画像は再生成されません。よろしいですか？');">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary btn-sm">LINEに再送</button>
  </form>
  <?php endif; ?>

  <span class="badge-status badge-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" style="margin-left:auto"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
  <div class="card">
    <div class="card-header">受講者情報</div>
    <div class="card-body" style="font-size:13px">
      <div style="margin-bottom:6px"><span style="color:var(--muted)">名前：</span><?= htmlspecialchars($request['display_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
      <div style="margin-bottom:6px"><span style="color:var(--muted)">LINE ID：</span><code style="font-size:11px"><?= htmlspecialchars($request['line_user_id'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></div>
      <div style="margin-bottom:6px"><span style="color:var(--muted)">依頼日時：</span><?= htmlspecialchars(date('Y/m/d H:i:s', strtotime((string)$request['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
      <div><span style="color:var(--muted)">入力タイプ：</span><?= htmlspecialchars($request['input_type'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">入力テキスト</div>
    <div class="card-body">
      <div class="prompt-box"><?= nl2br(htmlspecialchars($request['input_text'] ?? '', ENT_QUOTES, 'UTF-8')) ?></div>
      <?php $firstPrompt = $prompts[0] ?? null; ?>
      <?php if ($firstPrompt && !empty($firstPrompt['input_summary_ja'])): ?>
      <div style="margin-top:10px;font-size:12px;color:var(--muted)">AIの解釈：<?= htmlspecialchars($firstPrompt['input_summary_ja'], ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!empty($request['error_message'])): ?>
<div class="alert alert-error" style="margin-bottom:16px">エラー：<?= htmlspecialchars($request['error_message'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($generationConfig)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header">このクライアントで実際に使う生成設定</div>
  <div class="card-body" style="display:flex;gap:18px;flex-wrap:wrap;font-size:12px">
    <div><span style="color:var(--muted)">テナント：</span><?= htmlspecialchars((string)$generationConfig['tenant'], ENT_QUOTES, 'UTF-8') ?></div>
    <div><span style="color:var(--muted)">エンジン：</span><?= htmlspecialchars((string)$generationConfig['engine'], ENT_QUOTES, 'UTF-8') ?></div>
    <div><span style="color:var(--muted)">モデル：</span><?= htmlspecialchars((string)$generationConfig['model'], ENT_QUOTES, 'UTF-8') ?></div>
    <div><span style="color:var(--muted)">品質：</span><?= htmlspecialchars((string)$generationConfig['quality'], ENT_QUOTES, 'UTF-8') ?></div>
    <div><span style="color:var(--muted)">生成上限：</span><?= (int)$generationConfig['max_images'] ?>枚</div>
    <div><span style="color:var(--muted)">APIキー：</span>
      OpenAI <?= !empty($generationConfig['openai_key']) ? '設定済み' : '未設定' ?> /
      Stability <?= !empty($generationConfig['stability_key']) ? '設定済み' : '未設定' ?> /
      Grok <?= !empty($generationConfig['grok_key']) ? '設定済み' : '未設定' ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php foreach ($promptMap as $type => $prompt): ?>
<?php $groupImages = $imageGroups[$type] ?? []; ?>
<div class="card" style="margin-bottom:12px">
  <div class="card-header" style="color:var(--accent2)">
    Prompt <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($prompt['title_ja'] ?? '', ENT_QUOTES, 'UTF-8') ?>
  </div>
  <div class="card-body">
    <?php if (!empty($prompt['prompt_en'])): ?>
    <div class="prompt-box" style="margin-bottom:12px"><?= htmlspecialchars($prompt['prompt_en'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($prompt['safety_notes'])): ?>
    <div style="font-size:12px;color:var(--warning)">注意：<?= htmlspecialchars($prompt['safety_notes'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($groupImages): ?>
    <div class="image-grid" style="margin-top:12px">
      <?php foreach ($groupImages as $img): ?>
      <a href="<?= htmlspecialchars($img['image_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
        <img src="<?= htmlspecialchars($img['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>-<?= (int)$img['image_no'] ?>" loading="lazy">
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="color:var(--muted);font-size:12px;margin-top:8px">画像はまだ生成されていません</div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$promptMap && $imageGroups): ?>
<div class="card" style="margin-bottom:12px">
  <div class="card-header">生成済み画像</div>
  <div class="card-body">
    <?php foreach ($imageGroups as $type => $groupImages): ?>
    <div style="font-weight:700;margin:8px 0">作品 <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="image-grid" style="margin-bottom:12px">
      <?php foreach ($groupImages as $img): ?>
      <a href="<?= htmlspecialchars($img['image_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
        <img src="<?= htmlspecialchars($img['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>-<?= (int)$img['image_no'] ?>" loading="lazy">
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$promptMap && !$imageGroups): ?>
<div class="alert alert-info">プロンプトや画像はまだ生成されていません。ステータス：<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($logs)): ?>
<div class="card">
  <div class="card-header">処理ログ</div>
  <div class="card-body">
    <?php foreach ($logs as $log): ?>
    <div class="log-item">
      <span class="log-time"><?= htmlspecialchars(date('H:i:s', strtotime((string)$log['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
      <span class="log-level-<?= htmlspecialchars($log['log_level'] ?? '', ENT_QUOTES, 'UTF-8') ?>">[<?= htmlspecialchars(strtoupper((string)($log['log_level'] ?? '')), ENT_QUOTES, 'UTF-8') ?>]</span>
      <span style="color:var(--muted)">[<?= htmlspecialchars($log['log_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>]</span>
      <span><?= htmlspecialchars($log['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
