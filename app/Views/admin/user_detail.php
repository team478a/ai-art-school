<?php
$pageTitle = '受講生詳細';
ob_start();

$updated = !empty($_GET['updated']);
$sent = !empty($_GET['sent']);
$usageUpdated = !empty($_GET['usage_updated']);
$testModeUpdated = !empty($_GET['test_mode_updated']);
$testModeError = trim((string)($_GET['test_mode_error'] ?? ''));
$testModeErrorMessages = [
    'invalid_until' => '有効期限の形式が正しくありません。',
    'expired_until' => '有効期限には現在より後の日時を指定してください。',
    'save_failed' => 'テストモードを保存できませんでした。',
];

$statusLabels = [
    'active' => '有効',
    'suspended' => '一時停止',
    'banned' => '禁止',
];

$memberLabels = [
    'none' => '一般',
    'subscriber' => 'サブスク会員',
];

$ticketReasons = [
    'manual' => '手動変更',
    'purchase' => '購入',
    'use' => '使用',
    'return' => '返却',
    'refund' => '返金',
];

$usage = $todayUsage ?? [
    'actual_count' => 0,
    'failed_count' => 0,
    'effective_count' => 0,
    'override_count' => null,
    'memo' => '',
    'updated_at' => null,
];

$lineName = trim((string)($user['display_name'] ?? ''));
$realName = trim((string)($user['real_name'] ?? ''));
$mainName = $realName !== '' ? $realName : ($lineName !== '' ? $lineName : '-');
$profileCompleted = !empty($user['profile_completed_at']);
$testModeUntilValue = '';
$testModeUntilTs = false;
if (!empty($user['generation_test_until'])) {
    $testModeUntilTs = strtotime((string)$user['generation_test_until']);
    if ($testModeUntilTs !== false) {
        $testModeUntilValue = date('Y-m-d\TH:i', $testModeUntilTs);
    }
}
$testModeCurrentlyActive = !empty($user['generation_test_enabled'])
    && (empty($user['generation_test_until']) || ($testModeUntilTs !== false && $testModeUntilTs >= time()))
    && (string)($user['status'] ?? 'active') === 'active';
?>

<?php if ($updated): ?>
  <div class="alert alert-success">更新しました。</div>
<?php endif; ?>
<?php if ($sent): ?>
  <div class="alert alert-success">LINEメッセージを送信しました。</div>
<?php endif; ?>
<?php if ($usageUpdated): ?>
  <div class="alert alert-success">本日の生成数を更新しました。</div>
<?php endif; ?>
<?php if ($testModeUpdated): ?>
  <div class="alert alert-success">生成テストモードを更新しました。</div>
<?php endif; ?>
<?php if ($testModeError !== ''): ?>
  <div class="alert alert-error"><?= htmlspecialchars($testModeErrorMessages[$testModeError] ?? 'テストモードを更新できませんでした。', ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<style>
  .user-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:16px}
  .profile-avatar{width:72px;height:72px;border-radius:50%;object-fit:cover;margin-bottom:10px}
  .ticket-change-plus{color:#16a34a;font-weight:800}
  .ticket-change-minus{color:#dc2626;font-weight:800}
  .metric-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;text-align:center}
  .metric-box{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px}
  .metric-label{font-size:11px;color:var(--muted)}
  .metric-value{font-size:22px;font-weight:900}
  @media(max-width:900px){
    .user-grid{grid-template-columns:1fr}
    .metric-grid{grid-template-columns:1fr}
  }
</style>

<div style="margin-bottom:16px">
  <a href="/admin/users" class="btn btn-secondary btn-sm">← 一覧へ戻る</a>
</div>

<div class="user-grid">
  <div>
    <div class="card" style="margin-bottom:12px">
      <div class="card-header">生成テストモード</div>
      <div class="card-body">
        <div style="margin-bottom:10px">
          <?php if ($testModeCurrentlyActive): ?>
            <span class="badge badge-success">有効</span>
          <?php elseif (!empty($user['generation_test_enabled'])): ?>
            <span class="badge badge-warning">期限切れまたは停止中</span>
          <?php else: ?>
            <span class="badge">無効</span>
          <?php endif; ?>
        </div>
        <p style="font-size:12px;color:var(--muted);line-height:1.7">
          指定ユーザーだけ、通常の受付日時や参加条件に関係なく生成できるようにします。
        </p>
        <form method="POST" action="/admin/users/<?= (int)$user['id'] ?>/generation-test">
          <?= csrf_field() ?>
          <input type="hidden" name="generation_test_enabled" value="0">
          <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
            <input type="checkbox" name="generation_test_enabled" value="1" <?= !empty($user['generation_test_enabled']) ? 'checked' : '' ?>>
            このユーザーをテスト対象にする
          </label>
          <div class="form-group">
            <label>有効期限（任意）</label>
            <input type="datetime-local" name="generation_test_until" value="<?= htmlspecialchars($testModeUntilValue, ENT_QUOTES, 'UTF-8') ?>">
            <p style="font-size:11px;color:var(--muted);margin-top:4px">空欄の場合は無期限です。</p>
          </div>
          <div class="form-group">
            <label>管理メモ</label>
            <input type="text" name="generation_test_memo" maxlength="255" value="<?= htmlspecialchars((string)($user['generation_test_memo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="例：導入前の動作確認">
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="width:100%">テスト設定を保存</button>
        </form>
        <p style="font-size:11px;color:var(--muted);line-height:1.6;margin:10px 0 0">
          受付日・曜日・時間、出席条件、1日・期間上限のみを迂回します。アカウント停止、API残高不足、生成API障害は迂回しません。
        </p>
      </div>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="card-body" style="text-align:center">
        <?php if (!empty($user['picture_url'])): ?>
          <img src="<?= htmlspecialchars($user['picture_url'], ENT_QUOTES, 'UTF-8') ?>" class="profile-avatar" alt="">
        <?php endif; ?>
        <div style="font-size:16px;font-weight:800"><?= htmlspecialchars($mainName, ENT_QUOTES, 'UTF-8') ?></div>
        <div style="font-size:12px;color:var(--muted);margin-top:4px">
          LINE名：<?= htmlspecialchars($lineName !== '' ? $lineName : '-', ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:8px">
          登録日 <?= !empty($user['created_at']) ? date('Y/m/d', strtotime($user['created_at'])) : '-' ?>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="card-header">プロフィール情報</div>
      <div class="card-body" style="font-size:13px;line-height:1.8">
        <div><strong>本名：</strong><?= htmlspecialchars($realName !== '' ? $realName : '未登録', ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>LINE名：</strong><?= htmlspecialchars($lineName !== '' ? $lineName : '-', ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>電話番号：</strong><?= htmlspecialchars($user['phone'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>郵便番号：</strong><?= htmlspecialchars($user['postal_code'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>住所：</strong><?= nl2br(htmlspecialchars($user['address'] ?? '-', ENT_QUOTES, 'UTF-8')) ?></div>
        <?php if (!empty($user['profile_note'])): ?>
          <div><strong>備考：</strong><?= nl2br(htmlspecialchars($user['profile_note'], ENT_QUOTES, 'UTF-8')) ?></div>
        <?php endif; ?>
        <div style="margin-top:8px;color:var(--muted);font-size:12px">
          プロフィール：<?= $profileCompleted ? '登録済み' : '未登録' ?>
          <?php if ($profileCompleted): ?>
            / <?= date('Y/m/d H:i', strtotime($user['profile_completed_at'])) ?>
          <?php endif; ?>
        </div>
        <div style="margin-top:8px;color:var(--muted);font-size:12px">
          参加者にはLINE登録後、プロフィール設定ページで本名・住所・電話番号を登録してもらいます。
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="card-header">ステータス変更</div>
      <div class="card-body">
        <form method="POST" action="/admin/users/<?= (int)$user['id'] ?>/status">
          <?= csrf_field() ?>
          <div class="form-group">
            <select name="status">
              <?php foreach ($statusLabels as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= ($user['status'] ?? '') === $value ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-secondary btn-sm" style="width:100%">変更する</button>
        </form>
      </div>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="card-header">会員区分・チケット</div>
      <div class="card-body">
        <form method="POST" action="/admin/users/<?= (int)$user['id'] ?>/member-type">
          <?= csrf_field() ?>
          <div class="form-group">
            <label>会員区分</label>
            <select name="member_type">
              <?php foreach ($memberLabels as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= ($user['member_type'] ?? 'none') === $value ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-secondary btn-sm" style="width:100%">会員区分を変更</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">

        <div style="text-align:center;margin-bottom:10px">
          <span style="font-size:12px;color:var(--muted)">チケット残数</span>
          <div style="font-size:28px;font-weight:900;color:var(--accent2)"><?= (int)($user['ticket_balance'] ?? 0) ?>枚</div>
          <?php if (!empty($user['ticket_expires_at'])): ?>
            <div style="font-size:11px;color:var(--muted)">期限 <?= date('Y/m/d', strtotime($user['ticket_expires_at'])) ?></div>
          <?php endif; ?>
        </div>

        <form method="POST" action="/admin/users/<?= (int)$user['id'] ?>/tickets">
          <?= csrf_field() ?>
          <div class="form-group">
            <label>チケット増減</label>
            <input type="number" name="ticket_count" value="1" step="1">
            <p style="font-size:11px;color:var(--muted);margin-top:4px">減らす場合は -1 のように入力します。</p>
          </div>
          <div class="form-group">
            <label>メモ</label>
            <input type="text" name="ticket_memo" placeholder="例：体験特典、調整、返却対応">
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="width:100%">反映する</button>
        </form>
      </div>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="card-header">本日の生成数調整</div>
      <div class="card-body">
        <div class="metric-grid">
          <div class="metric-box">
            <div class="metric-label">成功カウント</div>
            <div class="metric-value" style="color:var(--accent2)"><?= (int)$usage['actual_count'] ?></div>
          </div>
          <div class="metric-box">
            <div class="metric-label">失敗・除外</div>
            <div class="metric-value" style="color:#dc2626"><?= (int)$usage['failed_count'] ?></div>
          </div>
          <div class="metric-box">
            <div class="metric-label">現在の判定</div>
            <div class="metric-value" style="color:#16a34a"><?= (int)$usage['effective_count'] ?></div>
          </div>
        </div>

        <p style="font-size:12px;color:var(--muted);line-height:1.7;margin:0 0 10px">
          APIエラーなどで生成に失敗した場合は、失敗分を除外して本日の利用数を調整できます。
          例えば制限に達してしまった受講生を再度生成できるようにする場合は、補正後の生成数を 0 などに変更してください。
          この補正は本日分だけに適用されます。
        </p>

        <?php if ($usage['override_count'] !== null): ?>
          <p style="font-size:12px;color:var(--muted);margin:0 0 10px">
            現在の補正値：<?= (int)$usage['override_count'] ?>
            <?php if (!empty($usage['updated_at'])): ?>
              / <?= date('m/d H:i', strtotime($usage['updated_at'])) ?>
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <form method="POST" action="/admin/users/<?= (int)$user['id'] ?>/generation-usage" style="margin-bottom:8px">
          <?= csrf_field() ?>
          <input type="hidden" name="usage_action" value="set">
          <div class="form-group">
            <label>補正後の本日生成数</label>
            <input type="number" name="override_count" value="<?= (int)$usage['effective_count'] ?>" min="0" max="999" step="1">
          </div>
          <div class="form-group">
            <label>メモ</label>
            <input type="text" name="usage_memo" value="<?= htmlspecialchars($usage['memo'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例：APIエラー分を除外">
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="width:100%">生成数を変更</button>
        </form>

        <?php if ($usage['override_count'] !== null): ?>
          <form method="POST" action="/admin/users/<?= (int)$user['id'] ?>/generation-usage">
            <?= csrf_field() ?>
            <input type="hidden" name="usage_action" value="clear">
            <button type="submit" class="btn btn-secondary btn-sm" style="width:100%">補正を解除</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="card-header">LINEメッセージ送信</div>
      <div class="card-body">
        <form method="POST" action="/admin/users/<?= (int)$user['id'] ?>/message">
          <?= csrf_field() ?>
          <div class="form-group">
            <textarea name="message" rows="3" placeholder="送信するメッセージ"></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="width:100%">送信</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">管理メモ</div>
      <div class="card-body">
        <form method="POST" action="/admin/users/<?= (int)$user['id'] ?>/memo">
          <?= csrf_field() ?>
          <div class="form-group">
            <textarea name="memo" rows="3"><?= htmlspecialchars($user['memo'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
          <button type="submit" class="btn btn-secondary btn-sm" style="width:100%">保存</button>
        </form>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:12px">
      <div class="card-header">チケット履歴</div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>日時</th>
              <th>種別</th>
              <th>増減</th>
              <th>残数</th>
              <th>メモ</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($ticketLogs as $log): ?>
            <?php $change = (int)$log['change_count']; ?>
            <tr>
              <td style="white-space:nowrap;color:var(--muted);font-size:12px"><?= date('m/d H:i', strtotime($log['created_at'])) ?></td>
              <td><?= htmlspecialchars($ticketReasons[$log['reason']] ?? $log['reason'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="<?= $change >= 0 ? 'ticket-change-plus' : 'ticket-change-minus' ?>"><?= $change >= 0 ? '+' : '' ?><?= $change ?></td>
              <td><?= $log['balance_after'] === null ? '-' : (int)$log['balance_after'] ?></td>
              <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($log['memo'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($ticketLogs)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">チケット履歴はまだありません。</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="card-header">教室参加履歴</div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>日付</th>
              <th>教室名</th>
              <th>状態</th>
              <th>支払い</th>
              <th>参加日時</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($attendances as $a): ?>
            <tr>
              <td><?= !empty($a['class_date']) ? date('Y/m/d', strtotime($a['class_date'])) : '-' ?></td>
              <td><?= htmlspecialchars($a['title'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($a['status'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($a['payment_status'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td style="color:var(--muted)"><?= !empty($a['attended_at']) ? date('m/d H:i', strtotime($a['attended_at'])) : '-' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($attendances)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">参加履歴はありません。</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">画像生成履歴</div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>日時</th>
              <th>入力</th>
              <th>画像数</th>
              <th>状態</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td style="color:var(--muted);white-space:nowrap"><?= date('m/d H:i', strtotime($r['created_at'])) ?></td>
              <td><?= htmlspecialchars(mb_strimwidth($r['input_text'] ?? '', 0, 34, '...'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= (int)$r['image_count'] ?></td>
              <td><?= htmlspecialchars($r['status'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><a href="/admin/image-requests/<?= (int)$r['id'] ?>" class="btn btn-secondary btn-sm">詳細</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($requests)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">画像生成履歴はありません。</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
