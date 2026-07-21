# v5.2.7 クライアント疎通チェック

## 目的

新しいクライアントを追加した後、LINE、LIFF、Stripe、AI API、公開ページ、運用ルールの設定漏れを1画面で確認できるようにしました。

## 追加内容

- クライアント別設定画面に「疎通チェック」ボタンを追加
- `/admin/tenants/{id}/diagnostics` を追加
- LINE Webhook URL、Stripe Webhook URL、LIFF URLを表示
- LINE、LIFF、Stripe、AI生成、公開ページ、運用ルールの設定状態を判定
- `price_` ではないStripe IDを要確認として表示
- 外部APIへ本番通信せず、安全に設定漏れと手動テスト手順を確認

## 運用手順

1. オーナーでクライアント別設定を開きます。
2. 「疎通チェック」を押します。
3. 要確認の項目を設定します。
4. LINE Developers、Stripe Dashboard、LIFF画面で手動テストを行います。
5. OKが揃ったら、予約、承認、参加確認、画像生成、購入の通しテストを行います。

## 注意

- この画面は本番LINEやStripeへ実通信しません。
- 実通信テストは、LINE DevelopersのWebhook検証、Stripe DashboardのWebhook送信、LIFF画面での購入テストで行ってください。
- シークレットキーやアクセストークンの実値は画面上に必要以上に表示しません。
