# v5.1.5 tenant write scope

SaaS化に向けて、LINE WebhookとStripe Webhookから作成・更新される主要データにクライアント識別を反映します。

## 対応内容

- LINEから作成されるユーザー、予約、画像生成依頼、生成ジョブへ `tenant_id` を付与
- LINEのマイページ、履歴、再生成、日次生成数、当日参加判定をテナント単位で判定
- 友だち追加・ブロック時のユーザー状態更新をテナント単位に限定
- Stripe Webhookのチケット・サブスク・教室決済更新をテナント単位に限定
- 生成数補正テーブル `image_request_usage_overrides` に `tenant_id` を追加

## 運用メモ

このバージョンは、クライアント別データ分離の「書き込み側」の基礎対応です。
完全SaaS化では、次に公開LIFFページ、Stripe Checkout、LINE Webhook URLのテナント判定を強化します。

## 注意

既存テーブルに `tenant_id` がない場合は、従来通りの処理で動作します。
先に v5.1.3 の「テナントデータ基盤」を適用してから、この更新を適用してください。
