# v5.1.6 tenant public entry scope

SaaS運用で共有ドメインを使う場合でも、公開側の入口から対象クライアントを判定できるようにしました。

## 対応した入口

- LINE Webhook: `/webhook/line/{tenant_key}`
- Stripe Webhook: `/stripe/webhook/{tenant_key}`
- LIFF予約カレンダー: `/liff/calendar?tenant={tenant_key}`
- LIFF購入ページ: `/liff/shop?tenant={tenant_key}`
- LIFFガチャ: `/liff/gacha?tenant={tenant_key}`

## 判定順

1. `tenant`、`client`、`tenant_key` のクエリ文字列
2. `X-AIART-TENANT`、`X-TENANT-KEY` ヘッダー
3. `/webhook/line/{tenant_key}`、`/stripe/webhook/{tenant_key}` のパス
4. `tenants.primary_domain` に登録されたドメイン
5. デフォルトクライアント

## 管理画面

クライアント別設定画面に、LINE Developers、Stripe、LIFFへ登録するための専用URLを表示します。

## 注意

既存の `/webhook/line`、`/stripe/webhook` も引き続き利用できます。共有ドメインで複数クライアントを扱う場合は、クライアントキー付きURLを使ってください。
