<?php
$serviceName = (string)($view['serviceName'] ?? 'AIアート教室');
$tenantKey = (string)($view['tenantKey'] ?? '');
$liffId = (string)($view['liffId'] ?? '');
$products = is_array($view['products'] ?? null) ? $view['products'] : [];
$checkoutPath = '/liff/shop/checkout' . ($tenantKey !== '' ? '?tenant=' . rawurlencode($tenantKey) : '');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>購入・会員メニュー</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        *,*::before,*::after{box-sizing:border-box}body{margin:0;background:#f5f6fa;color:#182033;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}.wrap{max-width:760px;margin:0 auto;padding:18px 16px 48px}.hero{background:#6655e8;color:#fff;padding:24px 20px;border-radius:8px}.hero h1{margin:0 0 8px;font-size:26px;letter-spacing:0}.hero p{margin:0;line-height:1.75}.notice{margin:16px 0;padding:14px 16px;border-radius:8px;background:#eaf8ef;color:#176b3a}.notice.cancel{background:#fff4df;color:#8a5700}.error{display:none;margin:16px 0;padding:14px 16px;border-radius:8px;background:#ffe8e8;color:#b42318;line-height:1.6}.intro{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:18px 0}.step{background:#fff;border:1px solid #dde2eb;padding:14px;border-radius:8px;line-height:1.55}.step strong{display:block;margin-bottom:4px}.section{background:#fff;border:1px solid #dde2eb;border-radius:8px;padding:18px}.section h2{font-size:20px;margin:0 0 14px}.products{display:grid;gap:12px}.product{border:1px solid #dde2eb;border-radius:8px;padding:16px}.product h3{font-size:18px;margin:0 0 6px}.product p{color:#61708a;margin:0 0 12px;line-height:1.6}.price{color:#5c4ee5;font-size:20px;font-weight:800;margin-bottom:12px}.buy{width:100%;border:0;border-radius:8px;background:#6655e8;color:#fff;font-size:16px;font-weight:800;padding:14px;cursor:pointer}.buy:disabled{background:#aeb5c4;cursor:wait}.empty{color:#667085;line-height:1.7}.foot{margin-top:16px;text-align:center;color:#7b8598;font-size:12px}@media(max-width:560px){.wrap{padding:12px 12px 36px}.intro{grid-template-columns:1fr}.hero h1{font-size:23px}}
    </style>
</head>
<body>
<main class="wrap">
    <section class="hero">
        <h1>購入・会員メニュー</h1>
        <p><?= htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') ?>の回数券、月額・年額会員、一回払いの商品を購入できます。決済はショッピングシステムの安全な画面で行います。</p>
    </section>
    <?php if (!empty($view['completed'])): ?><div class="notice">購入を受け付けました。受講権への反映まで少しお待ちください。</div><?php endif; ?>
    <?php if (!empty($view['cancelled'])): ?><div class="notice cancel">購入をキャンセルしました。料金は発生していません。</div><?php endif; ?>
    <div id="error" class="error" role="alert"></div>
    <section class="intro">
        <div class="step"><strong>1. 商品を選ぶ</strong>回数券、月額、年額、一回払いから選択します。</div>
        <div class="step"><strong>2. 決済する</strong>ショッピングシステムの決済画面で支払います。</div>
        <div class="step"><strong>3. 権利を受け取る</strong>決済後、受講権がこのサービスへ自動反映されます。</div>
    </section>
    <section class="section">
        <h2>商品一覧</h2>
        <?php if (!$products): ?>
            <p class="empty">現在購入できる商品はありません。管理者がショッピング商品対応表を設定すると表示されます。</p>
        <?php else: ?>
            <div class="products">
                <?php foreach ($products as $product): ?>
                    <article class="product">
                        <h3><?= htmlspecialchars((string)($product['label'] ?? $product['key'] ?? '商品'), ENT_QUOTES, 'UTF-8') ?></h3>
                        <?php if (!empty($product['description'])): ?><p><?= htmlspecialchars((string)$product['description'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                        <?php if (!empty($product['display_price'])): ?><div class="price"><?= htmlspecialchars((string)$product['display_price'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                        <button class="buy" type="button" data-product="<?= htmlspecialchars((string)($product['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">購入手続きへ</button>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <p class="foot">決済後の権利反映には数秒かかる場合があります。</p>
</main>
<script>
(() => {
    const liffId = <?= json_encode($liffId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const checkoutPath = <?= json_encode($checkoutPath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const errorBox = document.getElementById('error');
    let profile = null;
    let idToken = '';

    const showError = (message) => {
        errorBox.textContent = message || '購入画面を開けませんでした。時間を置いてもう一度お試しください。';
        errorBox.style.display = 'block';
        errorBox.scrollIntoView({behavior: 'smooth', block: 'center'});
    };

    const initialize = async () => {
        if (!liffId) {
            showError('購入用LIFF IDが設定されていません。管理者へお問い合わせください。');
            return;
        }
        await liff.init({liffId});
        if (!liff.isLoggedIn()) {
            liff.login({redirectUri: location.href});
            return;
        }
        profile = await liff.getProfile();
        idToken = liff.getIDToken() || '';
    };

    const checkout = async (button) => {
        const original = button.textContent;
        button.disabled = true;
        button.textContent = '準備中...';
        errorBox.style.display = 'none';
        try {
            if (!profile) await initialize();
            if (!profile) throw new Error('LINE認証が完了していません。LINEアプリ内で開き直してください。');
            const response = await fetch(checkoutPath, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({
                    product_key: button.dataset.product,
                    line_user_id: profile.userId,
                    id_token: idToken
                })
            });
            const raw = await response.text();
            let data = {};
            try { data = raw ? JSON.parse(raw) : {}; } catch (_) {
                throw new Error('決済サーバーから正しい応答がありませんでした。管理者へお問い合わせください。');
            }
            if (!response.ok || !data.checkout_url) throw new Error(data.error || '決済ページを作成できませんでした。');
            location.href = data.checkout_url;
        } catch (error) {
            showError(error && error.message ? error.message : '購入処理に失敗しました。');
            button.disabled = false;
            button.textContent = original;
        }
    };

    document.querySelectorAll('.buy').forEach((button) => button.addEventListener('click', () => checkout(button)));
    initialize().catch((error) => showError(error && error.message ? error.message : 'LINE認証に失敗しました。'));
})();
</script>
</body>
</html>
