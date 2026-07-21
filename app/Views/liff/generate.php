<?php
header('Content-Type: text/html; charset=UTF-8');
$liffId = (string)($liffId ?? '');
$serviceName = (string)($serviceName ?? 'AIアート画像生成');
$tenantKey = (string)($tenantKey ?? '');
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') ?></title>
  <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #f6f7fb;
      color: #172033;
    }
    .wrap { max-width: 720px; margin: 0 auto; padding: 18px; }
    .hero {
      background: linear-gradient(135deg, #6f56f5, #8f7dff);
      color: #fff;
      border-radius: 18px;
      padding: 24px 22px;
      margin-bottom: 16px;
    }
    .hero h1 {
      margin: 0 0 8px;
      font-size: 27px;
      line-height: 1.25;
      letter-spacing: 0;
    }
    .hero p {
      margin: 0;
      line-height: 1.7;
      color: rgba(255,255,255,.94);
    }
    .card {
      background: #fff;
      border: 1px solid #e0e4ef;
      border-radius: 14px;
      padding: 18px;
      margin-bottom: 14px;
    }
    label {
      display: block;
      font-weight: 800;
      margin-bottom: 10px;
    }
    textarea {
      width: 100%;
      min-height: 150px;
      border: 1px solid #d7ddeb;
      border-radius: 12px;
      padding: 14px;
      font-size: 16px;
      line-height: 1.6;
      resize: vertical;
      background: #fff;
    }
    button {
      width: 100%;
      border: 0;
      border-radius: 12px;
      background: #6f56f5;
      color: #fff;
      font-weight: 800;
      font-size: 17px;
      padding: 15px;
      margin-top: 12px;
    }
    button:disabled { opacity: .55; }
    .note {
      color: #667394;
      font-size: 14px;
      line-height: 1.7;
    }
    .alert {
      border-radius: 12px;
      padding: 14px;
      margin-bottom: 14px;
      line-height: 1.6;
    }
    .alert.error {
      background: #ffe7e7;
      color: #b91c1c;
      border: 1px solid #ffc7c7;
    }
    .alert.ok {
      background: #e9fbe9;
      color: #147a31;
      border: 1px solid #bfeec8;
    }
    .examples {
      display: grid;
      gap: 8px;
      margin-top: 10px;
    }
    .example {
      border: 1px solid #e0e4ef;
      border-radius: 10px;
      padding: 10px 12px;
      color: #52607d;
      background: #fafbff;
      line-height: 1.6;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <h1>画像生成</h1>
      <p>作りたい画像の内容を入力してください。完成した画像は、このLINEにお送りします。</p>
    </section>

    <?php if ($liffId === ''): ?>
      <div class="alert error">生成用LIFF IDが未設定です。管理画面のクライアント別設定でLIFF IDを設定してください。</div>
    <?php endif; ?>
    <div id="message"></div>

    <section class="card">
      <label for="inputText">作りたい画像</label>
      <textarea id="inputText" placeholder="例：明るい絵本風。海辺で親子が笑顔で歩いている、やさしい色合いのイラスト"></textarea>
      <button id="submitBtn" type="button" <?= $liffId === '' ? 'disabled' : '' ?>>生成を依頼する</button>
      <p class="note">
        生成できる曜日・時間・1日の上限は管理画面の設定に従います。LINEのトークに「生成」と入力するのではなく、この画面から依頼してください。
      </p>
      <div class="examples">
        <div class="example">明るい絵本風、かわいい雰囲気、自然な表情、やさしい色合い</div>
        <div class="example">人物の手と顔が自然に見える、清潔感のある高品質なイラスト</div>
      </div>
    </section>
  </div>

  <script>
    const LIFF_ID = <?= json_encode($liffId, JSON_UNESCAPED_UNICODE) ?>;
    const TENANT_KEY = <?= json_encode($tenantKey, JSON_UNESCAPED_UNICODE) ?>;
    const REQUEST_URL = '/liff/generate/request' + (TENANT_KEY ? '?tenant=' + encodeURIComponent(TENANT_KEY) : '');
    let profile = null;
    let idToken = '';

    function escapeHtml(value) {
      return String(value).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
      });
    }

    function showMessage(text, type) {
      document.getElementById('message').innerHTML = '<div class="alert ' + type + '">' + escapeHtml(text) + '</div>';
    }

    async function initLiff() {
      if (!LIFF_ID) return;
      try {
        await liff.init({ liffId: LIFF_ID });
        if (!liff.isLoggedIn()) {
          liff.login();
          return;
        }
        profile = await liff.getProfile();
        idToken = liff.getIDToken() || '';
      } catch (e) {
        showMessage('LINE認証に失敗しました。リッチメニューから開き直してください。改善しない場合は、管理者へ画像生成用LIFF IDの確認を依頼してください。', 'error');
      }
    }

    async function submitRequest() {
      const btn = document.getElementById('submitBtn');
      const inputText = document.getElementById('inputText').value.trim();
      if (!inputText) {
        showMessage('作りたい画像の内容を入力してください。', 'error');
        return;
      }
      if (!profile) {
        await initLiff();
      }
      if (!profile) {
        showMessage('LINE認証に失敗しました。LINEアプリ内から開き直してください。', 'error');
        return;
      }

      btn.disabled = true;
      btn.textContent = '送信中...';
      try {
        const res = await fetch(REQUEST_URL, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            idToken,
            lineUserId: profile.userId,
            displayName: profile.displayName || '',
            pictureUrl: profile.pictureUrl || '',
            referralToken: new URLSearchParams(window.location.search).get('ref') || '',
            inputText
          })
        });
        const responseText = await res.text();
        let data = null;
        if (responseText !== '') {
          try {
            data = JSON.parse(responseText);
          } catch (parseError) {
            const jsonStart = responseText.indexOf('{');
            const jsonEnd = responseText.lastIndexOf('}');
            if (jsonStart !== -1 && jsonEnd > jsonStart) {
              try {
                data = JSON.parse(responseText.slice(jsonStart, jsonEnd + 1));
              } catch (ignored) {
                data = null;
              }
            }
          }
        }

        const acceptedRequestId = res.headers.get('X-AIArt-Request-Id') || '';
        if (!data && res.ok && acceptedRequestId) {
          data = {
            ok: true,
            request_id: acceptedRequestId,
            message: '生成依頼を受け付けました。完成した画像はこのLINEにお送りします。'
          };
        }
        if (!data) {
          throw new Error('サーバーからの応答を確認できませんでした。依頼一覧に登録されている場合、再送する必要はありません。');
        }
        if (!data.ok) {
          throw new Error(data.message || '生成依頼に失敗しました。');
        }
        showMessage(data.message || '生成依頼を受け付けました。', 'ok');
        document.getElementById('inputText').value = '';
      } catch (e) {
        showMessage(e.message || '通信エラーが発生しました。時間を置いてもう一度お試しください。', 'error');
      } finally {
        btn.disabled = false;
        btn.textContent = '生成を依頼する';
      }
    }

    document.getElementById('submitBtn').addEventListener('click', submitRequest);
    initLiff();
  </script>
</body>
</html>
