<?php

require_once BASE_PATH . '/config/settings.php';

class LiffPaidController {
    public function show(): void {
        header('Content-Type: text/html; charset=UTF-8');

        $type = strtolower(trim((string)($_GET['type'] ?? '')));
        $messages = [
            'ticket' => ['回数券の購入が完了しました', '購入した回数券は、予約や参加確認時に自動で反映されます。'],
            'subscription' => ['月額サブスクの登録が完了しました', 'サブスク会員として、対象教室の参加判定に自動で反映されます。'],
            'annual_subscription' => ['年額サブスクの登録が完了しました', '年額会員として、対象教室の参加判定に自動で反映されます。'],
            'attendance' => ['参加費の支払いが完了しました', '当日の参加確認や支払い状況に自動で反映されます。'],
        ];
        $message = $messages[$type] ?? ['決済が完了しました', '購入内容はシステムに反映されます。反映まで少し時間がかかる場合があります。'];

        $liffId = trim((string)(Settings::get('shop_liff_id', '') ?: Settings::get('liff_id', '')));
        $tenant = Settings::currentTenant();
        $tenantKey = trim((string)($tenant['tenant_key'] ?? ''));
        $tenantQuery = $tenantKey !== '' ? '?tenant=' . rawurlencode($tenantKey) : '';
        $title = $this->esc($message[0]);
        $body = $this->esc($message[1]);
        $liffScript = $liffId !== ''
            ? '<script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script><script>liff.init({liffId:' . json_encode($liffId) . '}).catch(function(){});</script>'
            : '';

        echo '<!doctype html><html lang="ja"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
        echo '<title>' . $title . '</title>';
        echo '<style>
            body{margin:0;background:#f6f7fb;color:#111827;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
            .wrap{max-width:560px;margin:0 auto;padding:28px 18px 44px}
            .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px 22px;box-shadow:0 10px 28px rgba(15,23,42,.08)}
            .mark{width:64px;height:64px;border-radius:999px;background:#22c55e;color:#fff;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:800;margin:0 auto 16px}
            h1{font-size:24px;line-height:1.4;margin:0 0 12px;text-align:center}
            p{font-size:15px;line-height:1.8;color:#475569;margin:0 0 20px;text-align:center}
            .actions{display:grid;gap:10px;margin-top:22px}
            a,button{appearance:none;border:0;border-radius:12px;padding:14px 16px;text-align:center;text-decoration:none;font-weight:700;font-size:15px}
            .primary{background:#6d5df6;color:#fff}.secondary{background:#eef2ff;color:#3730a3}
            .note{font-size:12px;color:#64748b;margin-top:18px;text-align:center}
        </style>';
        echo $liffScript;
        echo '</head><body><main class="wrap"><section class="card">';
        echo '<div class="mark">✓</div><h1>' . $title . '</h1><p>' . $body . '</p>';
        echo '<div class="actions"><a class="primary" href="/liff/shop' . $this->esc($tenantQuery) . '">購入メニューへ戻る</a><a class="secondary" href="/liff/calendar' . $this->esc($tenantQuery) . '">予約カレンダーへ戻る</a><button type="button" onclick="closeLiff()">閉じる</button></div>';
        echo '<div class="note">画面が反映されない場合は、数秒後に購入メニューまたは予約カレンダーを開き直してください。</div>';
        echo '</section></main><script>
            function closeLiff(){ if(window.liff && liff.isInClient && liff.isInClient()){ liff.closeWindow(); } else { history.back(); } }
        </script></body></html>';
    }

    private function esc(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
