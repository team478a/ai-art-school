<?php
$pageTitle = 'クライアント診断';
ob_start();

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$statusLabel = static function (string $status): string {
    return match ($status) {
        'ok' => '正常',
        'warning' => '要注意',
        default => '要対応',
    };
};
$statusStyle = static function (string $status): string {
    return match ($status) {
        'ok' => 'background:#dcfce7;color:#166534;border:1px solid #86efac',
        'warning' => 'background:#fef9c3;color:#854d0e;border:1px solid #fde68a',
        default => 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5',
    };
};
?>

<?php if (!empty($saved) && $saved !== 'recovered'): ?>
  <div class="alert alert-success">クライアント情報を更新しました。</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= $h($error) ?></div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn btn-secondary" href="/admin/tenants/<?= (int)$tenant['id'] ?>/settings">クライアント設定へ戻る</a>
    <a class="btn btn-secondary" href="/admin/tenants/<?= (int)$tenant['id'] ?>/handover">引き継ぎメモ</a>
    <a class="btn btn-secondary" href="/admin/tenants/<?= (int)$tenant['id'] ?>/backups">バックアップ</a>
  </div>
  <div style="color:var(--muted)">
    対象: <strong><?= $h($tenant['name'] ?? '') ?></strong>
    <span style="margin-left:8px"><code><?= $h($tenant['tenant_key'] ?? '') ?></code></span>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header">生成停止時の安全な復旧</div>
  <div class="card-body">
    <?php if (is_array($recoveryResult)): ?>
      <div class="alert alert-success" style="margin-bottom:14px">
        復旧が完了しました。依頼を戻した件数: <strong><?= (int)$recoveryResult['requests_reset'] ?></strong>件、
        ジョブを戻した件数: <strong><?= (int)$recoveryResult['jobs_reset'] ?></strong>件、
        新しく再投入した件数: <strong><?= (int)$recoveryResult['jobs_queued'] ?></strong>件
        <?php if ((int)$recoveryResult['warnings_count'] > 0): ?>
          （確認事項 <?= (int)$recoveryResult['warnings_count'] ?>件）
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <p style="line-height:1.8;color:var(--muted);margin:0 0 14px">
      <strong><?= $h($tenant['name'] ?? '') ?></strong> のうち、<?= (int)$staleMinutes ?>分以上停止している生成処理だけを受信待ちへ戻します。
      他のクライアント、正常に処理中の依頼、完了済み画像には触れません。
    </p>
    <form method="post" action="/admin/tenants/<?= (int)$tenant['id'] ?>/diagnostics/recover" onsubmit="return confirm('このクライアントの停止中生成処理を復旧します。実行しますか？')">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <button class="btn btn-primary" type="submit">このクライアントの生成処理を復旧</button>
    </form>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header">導入チェック概要</div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
      <?php foreach ([
        ['label' => '完了率', 'value' => (int)($diagnostics['score'] ?? 0) . '%', 'color' => 'var(--accent)'],
        ['label' => '正常', 'value' => (int)($diagnostics['ok'] ?? 0), 'color' => '#16a34a'],
        ['label' => '要注意・未使用', 'value' => (int)($diagnostics['warnings'] ?? 0), 'color' => '#ca8a04'],
        ['label' => '要対応', 'value' => (int)($diagnostics['ng'] ?? 0), 'color' => '#dc2626'],
      ] as $summary): ?>
        <div style="padding:14px;border:1px solid var(--border);border-radius:8px;background:var(--surface-muted)">
          <div style="color:var(--muted);font-size:13px"><?= $h($summary['label']) ?></div>
          <div style="font-size:30px;font-weight:900;color:<?= $h($summary['color']) ?>"><?= $h($summary['value']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <p style="line-height:1.8;color:var(--muted);margin:12px 0 0">
      LINE、LIFF、Stripe、AI生成、公開ページ、運用ルールをクライアント単位で確認します。
      外部APIへの本番通信は行わず、設定値、URL、ID形式と手動テスト手順を確認します。
    </p>
  </div>
</div>

<?php foreach (($diagnostics['groups'] ?? []) as $group): ?>
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><?= $h($group['label'] ?? 'チェック') ?></div>
    <div class="card-body">
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th style="width:120px">状態</th><th>項目</th><th>値</th><th>確認内容</th></tr></thead>
          <tbody>
          <?php foreach (($group['items'] ?? []) as $item): ?>
            <?php $status = (string)($item['status'] ?? 'ng'); ?>
            <tr>
              <td><span style="display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:800;<?= $statusStyle($status) ?>"><?= $h($statusLabel($status)) ?></span></td>
              <td><strong><?= $h($item['label'] ?? '') ?></strong></td>
              <td style="word-break:break-all"><code><?= $h($item['value'] ?? '') ?></code></td>
              <td style="line-height:1.7;color:var(--muted)"><?= $h($item['hint'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($group['manual_test'])): ?>
        <details style="margin-top:14px;padding:12px;border:1px solid var(--border);border-radius:8px;background:var(--surface-muted)">
          <summary style="font-weight:800;cursor:pointer">手動テスト手順</summary>
          <ol style="line-height:1.9;color:var(--muted);padding-left:20px;margin-bottom:0">
            <?php foreach ($group['manual_test'] as $step): ?><li><?= $h($step) ?></li><?php endforeach; ?>
          </ol>
        </details>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php if (!empty($operationsAudit)): ?>
  <div class="card" style="margin-bottom:18px">
    <div class="card-header">運用監査サマリー</div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:repeat(3,minmax(120px,1fr));gap:12px">
        <?php foreach ([
          ['label' => '正常', 'key' => 'ok', 'style' => 'border-color:#86efac;background:#dcfce7;color:#166534'],
          ['label' => '要注意', 'key' => 'warning', 'style' => 'border-color:#fde68a;background:#fef9c3;color:#854d0e'],
          ['label' => '要対応', 'key' => 'ng', 'style' => 'border-color:#fca5a5;background:#fee2e2;color:#991b1b'],
        ] as $summary): ?>
          <div style="padding:14px;border:1px solid;border-radius:8px;<?= $summary['style'] ?>">
            <strong><?= $h($summary['label']) ?></strong>
            <div style="font-size:28px;font-weight:900"><?= (int)($operationsAudit['counts'][$summary['key']] ?? 0) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p style="margin:12px 0 0;color:var(--muted);line-height:1.8">生成ジョブ、CRON、障害、データ分離、バックアップ、管理者権限をクライアント単位で検査しています。</p>
    </div>
  </div>

  <?php foreach (($operationsAudit['sections'] ?? []) as $section): ?>
    <?php $sectionStatus = (string)($section['status'] ?? 'ng'); ?>
    <div class="card" style="margin-bottom:18px">
      <div class="card-header" style="display:flex;justify-content:space-between;gap:12px">
        <span><?= $h($section['label'] ?? '') ?></span>
        <span style="padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;<?= $statusStyle($sectionStatus) ?>"><?= $h($statusLabel($sectionStatus)) ?></span>
      </div>
      <div class="card-body" style="overflow-x:auto">
        <table>
          <thead><tr><th style="width:120px">状態</th><th>監査項目</th><th>現在値</th><th>対処・確認内容</th></tr></thead>
          <tbody>
          <?php foreach (($section['items'] ?? []) as $item): ?>
            <?php $itemStatus = (string)($item['status'] ?? 'ng'); ?>
            <tr>
              <td><span style="display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:800;<?= $statusStyle($itemStatus) ?>"><?= $h($statusLabel($itemStatus)) ?></span></td>
              <td><strong><?= $h($item['label'] ?? '') ?></strong></td>
              <td style="word-break:break-all"><?= $h($item['value'] ?? '') ?></td>
              <td style="color:var(--muted);line-height:1.7"><?= $h($item['hint'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="card">
  <div class="card-header">公開前の最終確認</div>
  <div class="card-body">
    <ol style="line-height:1.9;color:var(--muted);padding-left:20px;margin:0">
      <li>LINE友だち追加からプロフィール登録まで確認する</li>
      <li>予約、承認、当日案内、参加確認、画像生成まで確認する</li>
      <li>購入ページで月額、年額、回数券、一回払いの表示と決済を確認する</li>
      <li>Stripe Webhookによる決済後の反映を確認する</li>
      <li>管理者、スタッフ、オーナーの権限表示を確認する</li>
      <li>公開ページ、利用規約、プライバシーポリシー、特商法表記を確認する</li>
      <li>停止中クライアントで公開ページ、LIFF、Webhookが停止するか確認する</li>
    </ol>
  </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/app/Views/admin/layout.php';
