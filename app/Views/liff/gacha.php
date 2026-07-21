<?php
$liffId = $liffId ?? '';
$tenantKey = $tenantKey ?? '';
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>戦国クリエイター入陣ガチャ</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        body{margin:0;background:#f5f6fb;color:#111827;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .wrap{max-width:720px;margin:0 auto;padding:18px}
        .hero{background:linear-gradient(135deg,#111827,#6d5dfc);color:white;border-radius:18px;padding:24px 20px;margin-bottom:16px}
        .hero h1{font-size:26px;margin:0 0 8px}
        .card{background:white;border:1px solid #e5e7eb;border-radius:16px;padding:18px;margin:14px 0;box-shadow:0 8px 24px rgba(15,23,42,.04)}
        .muted{color:#64748b;line-height:1.7}
        .btn{display:block;width:100%;border:0;border-radius:14px;padding:16px;font-weight:800;font-size:17px;background:#6d5dfc;color:white}
        .btn.secondary{background:#111827}
        .btn.ghost{background:#eef2ff;color:#4f46e5}
        .btn:disabled{background:#cbd5e1;color:#64748b}
        .result{display:none;text-align:center}
        .rarity{font-size:42px;font-weight:900;margin:8px 0;color:#6d5dfc}
        .prize{font-size:24px;font-weight:900;margin:8px 0}
        .spin{display:none;margin:24px auto;width:116px;height:116px;border-radius:999px;border:10px solid #ddd6fe;border-top-color:#6d5dfc;animation:spin 1s linear infinite}
        .alert{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:14px;padding:14px;line-height:1.7}
        .ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
        textarea{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:12px;padding:12px;font-size:16px;min-height:84px}
        video{width:100%;border-radius:16px;margin-top:12px;background:#111827}
        @keyframes spin{to{transform:rotate(360deg)}}
    </style>
</head>
<body>
<div class="wrap">
    <section class="hero">
        <h1>戦国クリエイター入陣ガチャ</h1>
        <p>教室参加後に付与される参加権で、特典ガチャを1回引けます。</p>
    </section>

    <div id="notice" class="card muted">LINE認証を確認しています...</div>

    <section id="ready" class="card" style="display:none">
        <h2>ガチャ参加権</h2>
        <p id="classInfo" class="muted"></p>
        <button id="drawBtn" class="btn">ガチャを引く</button>
        <div id="spin" class="spin"></div>
    </section>

    <section id="result" class="card result">
        <h2>抽選結果</h2>
        <video id="video" controls playsinline style="display:none"></video>
        <div id="rarity" class="rarity"></div>
        <div id="prize" class="prize"></div>
        <p id="detail" class="muted"></p>
        <p id="expires" class="muted"></p>
        <textarea id="interestMessage" placeholder="購入希望・相談内容があれば入力してください"></textarea>
        <button id="interestBtn" class="btn secondary" style="margin-top:12px">この特典の案内を希望する</button>
        <p id="interestResult" class="muted"></p>
    </section>
</div>

<script>
const LIFF_ID = <?= json_encode((string)$liffId, JSON_UNESCAPED_UNICODE) ?>;
const TENANT_QUERY = <?= json_encode($tenantKey !== '' ? '?tenant=' . rawurlencode((string)$tenantKey) : '', JSON_UNESCAPED_UNICODE) ?>;
let idToken = '';

function showNotice(text, ok=false) {
    const el = document.getElementById('notice');
    el.textContent = text;
    el.className = 'card ' + (ok ? 'alert ok' : 'alert');
    el.style.display = 'block';
}

function post(url, data = {}) {
    const tenantUrl = url + (TENANT_QUERY && !url.includes('?') ? TENANT_QUERY : '');
    return fetch(tenantUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...data, idToken})
    }).then(r => r.json());
}

function renderResult(result) {
    document.getElementById('result').style.display = 'block';
    document.getElementById('rarity').textContent = result.rarity_name || '';
    document.getElementById('prize').textContent = result.prize_name || '';
    document.getElementById('detail').textContent = result.reward_detail || '';
    document.getElementById('expires').textContent = result.reward_expires_at ? '特典期限: ' + result.reward_expires_at : '';
    if (result.video_url) {
        const v = document.getElementById('video');
        v.src = result.video_url;
        v.style.display = 'block';
        v.play().catch(() => {});
    }
}

async function init() {
    try {
        if (!LIFF_ID) {
            showNotice('LIFF IDが未設定です。管理画面のLINE設定を確認してください。');
            return;
        }
        await liff.init({liffId: LIFF_ID});
        if (!liff.isLoggedIn()) {
            liff.login({redirectUri: location.href});
            return;
        }
        idToken = liff.getIDToken() || '';
        const status = await post('/liff/gacha/status');
        if (!status.ok) {
            showNotice(status.message || 'LINE認証に失敗しました。LINEアプリ内で開き直してください。');
            return;
        }
        if (!status.has_entitlement) {
            showNotice(status.message || '現在利用できるガチャ参加権はありません。');
            return;
        }
        showNotice('ガチャ参加権があります。', true);
        document.getElementById('ready').style.display = status.already_drawn ? 'none' : 'block';
        const e = status.entitlement || {};
        document.getElementById('classInfo').textContent = (e.class_title || '参加教室') + ' / 有効期限 ' + (e.expires_at || '');
        if (status.result) renderResult(status.result);
    } catch (e) {
        showNotice('読み込みに失敗しました。LINEアプリ内で開き直してください。');
    }
}

document.getElementById('drawBtn').addEventListener('click', async () => {
    const btn = document.getElementById('drawBtn');
    btn.disabled = true;
    document.getElementById('spin').style.display = 'block';
    const res = await post('/liff/gacha/draw');
    document.getElementById('spin').style.display = 'none';
    if (!res.ok) {
        showNotice(res.message || '抽選に失敗しました。');
        btn.disabled = false;
        return;
    }
    document.getElementById('ready').style.display = 'none';
    renderResult(res.result);
});

document.getElementById('interestBtn').addEventListener('click', async () => {
    const btn = document.getElementById('interestBtn');
    btn.disabled = true;
    const res = await post('/liff/gacha/interest', {message: document.getElementById('interestMessage').value});
    document.getElementById('interestResult').textContent = res.message || (res.ok ? '送信しました。' : '送信に失敗しました。');
    if (!res.ok) btn.disabled = false;
});

init();
</script>
</body>
</html>
