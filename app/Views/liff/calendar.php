<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>教室予約カレンダー</title>
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Hiragino Sans','Noto Sans JP',sans-serif;background:#f4f5f7;color:#111827}
.wrap{width:min(640px,100%);margin:0 auto;padding:18px 14px 32px}
.header{margin-bottom:14px}
.header h1{font-size:20px;margin:0 0 4px;color:#6d5df3}
.header p{font-size:13px;line-height:1.7;margin:0;color:#667085}
.notice{display:none;background:#fff;border:1px solid #d8def0;border-radius:10px;padding:14px;margin:12px 0 16px;box-shadow:0 1px 3px rgba(16,24,40,.05)}
.notice.show{display:block}
.notice h2{font-size:16px;margin:0 0 8px;color:#111827}
.notice p{font-size:13px;line-height:1.8;margin:6px 0;color:#52607a}
.notice .actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.notice a,.notice button{appearance:none;border:0;border-radius:8px;padding:10px 12px;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer}
.notice .primary{background:#06c755;color:#fff}
.notice .secondary{background:#eef1f7;color:#334155}
.event{background:#fff;border:1px solid #dfe3ec;border-radius:12px;padding:14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(16,24,40,.05)}
.event-head{display:flex;gap:12px;align-items:flex-start}
.datebox{min-width:58px;text-align:center;background:#6d5df3;color:#fff;border-radius:10px;padding:7px 6px}
.datebox .day{font-size:24px;font-weight:800;line-height:1}
.datebox .month{font-size:11px;margin-top:3px}
.title{font-size:16px;font-weight:800;margin:1px 0 3px}
.time{font-size:13px;color:#52607a}
.meta{font-size:13px;color:#52607a;line-height:1.7;margin-top:8px}
.badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;margin-left:4px}
.badge-real{background:#e9fbf2;color:#067647}
.badge-online{background:#eaf2ff;color:#2563eb}
.badge-full{background:#fee2e2;color:#dc2626}
.badge-wait{background:#fff7ed;color:#c2410c}
.bar{height:7px;background:#eef1f7;border-radius:99px;overflow:hidden;margin:10px 0 6px}
.bar div{height:100%;background:#6d5df3}
.bar.full div{background:#ef4444}
.btn{width:100%;border:0;border-radius:9px;padding:12px 14px;margin-top:10px;background:#6d5df3;color:#fff;font-size:15px;font-weight:800;cursor:pointer}
.btn.wait{background:#f97316}
.btn.cancel{background:#64748b}
.btn:disabled{background:#cbd5e1;color:#64748b;cursor:not-allowed}
.empty{background:#fff;border:1px dashed #cbd5e1;border-radius:12px;padding:28px 14px;text-align:center;color:#667085;font-size:14px;line-height:1.8}
#toast{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);width:min(520px,calc(100% - 24px));background:#111827;color:#fff;padding:12px 14px;border-radius:10px;font-size:14px;line-height:1.6;text-align:center;opacity:0;pointer-events:none;transition:opacity .2s;z-index:20}
#toast.show{opacity:1}
@media(max-width:420px){.wrap{padding:14px 10px 28px}.event{padding:12px}.title{font-size:15px}.notice .actions{display:grid}.notice a,.notice button{width:100%;text-align:center}}
</style>
</head>
<body>
<main class="wrap">
  <header class="header">
    <h1>教室予約カレンダー</h1>
    <p>参加したい教室を選んで予約してください。満席の場合はキャンセル待ちに登録できます。</p>
  </header>

  <section id="line-notice" class="notice" aria-live="polite">
    <h2 id="line-notice-title">LINE連携を確認しています</h2>
    <p id="line-notice-body">少しお待ちください。</p>
    <div class="actions" id="line-notice-actions"></div>
  </section>

  <section id="events"></section>
</main>
<div id="toast"></div>

<script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
<script>
const LIFF_ID = <?= json_encode($liffId ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const EVENTS = <?= json_encode($events ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const TENANT_KEY = new URLSearchParams(window.location.search).get('tenant') || '';
const tenantEndpoint = path => TENANT_KEY
  ? path + (path.includes('?') ? '&' : '?') + 'tenant=' + encodeURIComponent(TENANT_KEY)
  : path;
const FRIEND_URL = <?= json_encode($friendUrl ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const LIFF_DIRECT_URL = <?= json_encode($liffDirectUrl ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

let idToken = '';
let lineProfile = null;
let waitingScheduleIds = new Set();

function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = value == null ? '' : String(value);
  return div.innerHTML;
}

function toast(message) {
  const el = document.getElementById('toast');
  el.textContent = message;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 3800);
}

function showNotice(title, body, actions) {
  document.getElementById('line-notice-title').textContent = title;
  document.getElementById('line-notice-body').innerHTML = body;
  const actionBox = document.getElementById('line-notice-actions');
  actionBox.innerHTML = '';
  (actions || []).forEach(action => {
    const el = action.href ? document.createElement('a') : document.createElement('button');
    el.textContent = action.label;
    el.className = action.primary ? 'primary' : 'secondary';
    if (action.href) el.href = action.href;
    if (action.onClick) {
      el.type = 'button';
      el.addEventListener('click', action.onClick);
    }
    actionBox.appendChild(el);
  });
  document.getElementById('line-notice').classList.add('show');
}

function hideNotice() {
  document.getElementById('line-notice').classList.remove('show');
}

function renderEvents() {
  const container = document.getElementById('events');
  if (!EVENTS.length) {
    container.innerHTML = '<div class="empty">現在予約できる教室はありません。<br>次回の開催日をお待ちください。</div>';
    return;
  }

  const weekdays = ['日','月','火','水','木','金','土'];
  container.innerHTML = EVENTS.map(ev => {
    const dt = new Date(ev.date + 'T00:00:00');
    const reserved = Number(ev.reserved || 0);
    const capacity = Number(ev.capacity || 0);
    const waitlist = Number(ev.waitlist || 0);
    const ratio = capacity > 0 ? Math.min(100, Math.round(reserved / capacity * 100)) : 0;
    const format = ev.format === 'zoom' ? 'オンライン' : (ev.format === 'hybrid' ? '会場・オンライン' : '会場');
    const badgeClass = ev.format === 'realtime' ? 'badge-real' : 'badge-online';
    const fee = Number(ev.fee || 0);
    const fullBadge = ev.full ? '<span class="badge badge-full">満席</span>' : '';
    const waitBadge = waitlist > 0 ? `<span class="badge badge-wait">待ち ${waitlist}人</span>` : '';
    return `
      <article class="event">
        <div class="event-head">
          <div class="datebox">
            <div class="day">${dt.getDate()}</div>
            <div class="month">${dt.getMonth() + 1}月 ${weekdays[dt.getDay()]}</div>
          </div>
          <div>
            <div class="title">${escapeHtml(ev.title)} <span class="badge ${badgeClass}">${format}</span>${fullBadge}${waitBadge}</div>
            <div class="time">${escapeHtml(ev.start)}-${escapeHtml(ev.end)}</div>
            ${ev.organizer ? `<div class="meta">主催者：${escapeHtml(ev.organizer)}</div>` : ''}
          </div>
        </div>
        ${ev.location && ev.format !== 'zoom' ? `<div class="meta">会場：${escapeHtml(ev.location)}</div>` : ''}
        <div class="bar ${ev.full ? 'full' : ''}"><div style="width:${ratio}%"></div></div>
        <div class="meta">予約 ${reserved}${capacity > 0 ? ' / 定員' + capacity + '人' : '人'}　${fee > 0 ? '参加費 ' + fee.toLocaleString() + '円' : '無料'}</div>
        <button class="btn reserve-btn ${ev.full ? 'wait' : ''}" data-id="${ev.id}" data-full="${ev.full ? '1' : '0'}" data-mode="reserve">
          ${ev.full ? 'キャンセル待ちに登録' : 'この教室を予約する'}
        </button>
      </article>
    `;
  }).join('');

  document.querySelectorAll('.reserve-btn').forEach(btn => {
    btn.addEventListener('click', () => handleButton(Number(btn.dataset.id), btn));
  });
  applyWaitlistStatus();
  updateReserveButtons();
}

function applyWaitlistStatus() {
  document.querySelectorAll('.reserve-btn').forEach(btn => {
    const scheduleId = Number(btn.dataset.id);
    if (waitingScheduleIds.has(scheduleId)) {
      btn.dataset.mode = 'cancel_waitlist';
      btn.textContent = 'キャンセル待ちを取り消す';
      btn.classList.add('cancel');
      btn.classList.remove('wait');
    } else if (btn.dataset.full === '1') {
      btn.dataset.mode = 'reserve';
      btn.textContent = 'キャンセル待ちに登録';
      btn.classList.add('wait');
      btn.classList.remove('cancel');
    }
  });
}

function updateReserveButtons() {
  document.querySelectorAll('.reserve-btn').forEach(btn => {
    btn.disabled = !idToken;
  });
}

async function initLine() {
  renderEvents();
  updateReserveButtons();

  if (!LIFF_ID) {
    showNotice('LINE予約設定が未完了です', '管理者へお問い合わせください。設定が完了すると、LINE上から予約できるようになります。', FRIEND_URL ? [{ label: '友だち追加を開く', href: FRIEND_URL, primary: true }] : []);
    return;
  }

  if (typeof liff === 'undefined') {
    showNotice('LINE連携を読み込めませんでした', '通信状況を確認して、もう一度開いてください。', [{ label: '再読み込み', onClick: () => location.reload(), primary: true }]);
    return;
  }

  try {
    await liff.init({ liffId: LIFF_ID });
    if (!liff.isLoggedIn()) {
      if (liff.isInClient()) {
        liff.login();
        return;
      }
      showNotice('LINEアプリで開いてください', '予約にはLINE登録が必要です。友だち追加後、LINEアプリ内で予約ページを開いてください。', [
        ...(FRIEND_URL ? [{ label: '友だち追加', href: FRIEND_URL, primary: true }] : []),
        ...(LIFF_DIRECT_URL ? [{ label: 'LINEで予約を開く', href: LIFF_DIRECT_URL, primary: !FRIEND_URL }] : [])
      ]);
      return;
    }

    idToken = liff.getIDToken() || '';
    lineProfile = await liff.getProfile();
    hideNotice();
    updateReserveButtons();
    await loadWaitlistStatus();
  } catch (error) {
    showNotice('LINE認証を開始できませんでした', 'LINEアプリで開き直すか、友だち追加から予約ページを開いてください。', [
      ...(FRIEND_URL ? [{ label: '友だち追加', href: FRIEND_URL, primary: true }] : []),
      { label: '再読み込み', onClick: () => location.reload(), primary: !FRIEND_URL }
    ]);
  }
}

async function loadWaitlistStatus() {
  if (!idToken) return;
  try {
    const res = await fetch(tenantEndpoint('/liff/waitlist/status'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ idToken })
    });
    const data = await res.json();
    if (data.ok && Array.isArray(data.waitlist_schedule_ids)) {
      waitingScheduleIds = new Set(data.waitlist_schedule_ids.map(Number));
      applyWaitlistStatus();
    }
  } catch (error) {
    // Status loading is helpful but not required for reservations.
  }
}

function handleButton(scheduleId, button) {
  if (button.dataset.mode === 'cancel_waitlist') {
    cancelWaitlist(scheduleId, button);
    return;
  }
  reserve(scheduleId, button);
}

async function cancelWaitlist(scheduleId, button) {
  if (!idToken) {
    toast('LINE連携が完了していません。LINEアプリ内で開いてください。');
    return;
  }
  if (!confirm('この教室のキャンセル待ちを取り消しますか？')) {
    return;
  }

  const original = button.textContent;
  button.disabled = true;
  button.textContent = '取消中...';
  try {
    const res = await fetch(tenantEndpoint('/liff/waitlist/cancel'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ idToken, scheduleId })
    });
    const data = await res.json();
    if (data.ok) {
      waitingScheduleIds.delete(scheduleId);
      applyWaitlistStatus();
      button.disabled = false;
      toast(data.message || 'キャンセル待ちを取り消しました。');
      return;
    }
    button.disabled = false;
    button.textContent = original;
    toast(data.message || 'キャンセル待ちを取り消せませんでした。');
  } catch (error) {
    button.disabled = false;
    button.textContent = original;
    toast('通信エラーが発生しました。もう一度お試しください。');
  }
}

async function reserve(scheduleId, button) {
  if (!idToken) {
    toast('LINE連携が完了していません。LINEアプリ内で開いてください。');
    return;
  }

  const original = button.textContent;
  button.disabled = true;
  button.textContent = button.classList.contains('wait') ? '登録中...' : '予約中...';
  try {
    const res = await fetch(tenantEndpoint('/liff/reserve'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        idToken,
        scheduleId,
        displayName: lineProfile ? lineProfile.displayName : '',
        pictureUrl: lineProfile ? lineProfile.pictureUrl : '',
        referralToken: new URLSearchParams(window.location.search).get('ref') || ''
      })
    });
    const data = await res.json();
    if (data.ok) {
      if (data.payment_required && data.payment_url) {
        toast(data.message || 'お支払いへ進みます。');
        setTimeout(() => { location.href = data.payment_url; }, 700);
        return;
      }
      if (data.waitlist) {
        waitingScheduleIds.add(scheduleId);
        applyWaitlistStatus();
        button.disabled = false;
        toast(data.message || 'キャンセル待ちに登録しました。');
        return;
      }
      button.textContent = data.already ? '予約済み' : '予約完了';
      toast(data.message || '予約が完了しました。');
      return;
    }
    button.disabled = false;
    button.textContent = original;
    toast(data.message || '予約に失敗しました。');
  } catch (error) {
    button.disabled = false;
    button.textContent = original;
    toast('通信エラーが発生しました。もう一度お試しください。');
  }
}

initLine();
</script>
</body>
</html>
