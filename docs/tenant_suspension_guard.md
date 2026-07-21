# テナント停止ガード v5.3.0

## 目的

SaaS運用でクライアントを一時停止した場合に、公開ページ、LIFF、LINE Webhook、Stripe Webhook が誤って稼働し続けないようにします。

## 対象

- 公開ページ: `/`, `/terms`, `/privacy`, `/legal`, `/commercial-transactions`, `/tokushoho`
- LIFF: `/liff`, `/liff/*`
- LINE Webhook: `/webhook/line`, `/webhook/line/{tenant_key}`
- Stripe Webhook: `/stripe/webhook`, `/stripe/webhook/{tenant_key}`

## 挙動

- `tenants.status = active` の場合は通常通り動作します。
- `tenants.status = suspended` の場合は公開ページとLIFFを停止画面または403 JSONで止めます。
- Webhookは外部サービス側の再送ループを避けるため、HTTP 200で `tenant suspended` を返します。
- 管理画面は止めません。オーナーがログインしてテナントを復旧できるようにするためです。

## テナント判定

次の順番で対象テナントを探します。

1. `tenant`, `client`, `tenant_key` のGET/POST値
2. `X-AIART-TENANT`, `X-TENANT-KEY` ヘッダー
3. `/webhook/line/{tenant_key}` または `/stripe/webhook/{tenant_key}` のパス
4. アクセス中のホスト名と `tenants.primary_domain`

## 運用メモ

テナントを停止したい場合は、管理画面のテナント管理でステータスを停止にします。停止中もオーナーは管理画面から設定確認、バックアップ、復旧作業を行えます。
