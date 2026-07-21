# v5.3.1 クライアント導入チェック画面の日本語化

## 目的

SaaS化・横展開時に、新しいクライアントの初期設定状況を管理画面から確認しやすくするため、導入チェック画面と診断サービスを日本語で整理しました。

## 変更内容

- クライアント導入チェック画面を日本語表示に統一
- LINE、LIFF、Stripe、AI、公開ページ、運用設定をカテゴリ別に診断
- 月額、年額、回数券、一回払い、初回無料の設定確認を強化
- 手動確認チェックリストを追加
- 外部API通信は行わず、保存済み設定値の有無と形式を確認

## 確認できる項目

- LINE Messaging API の Channel Secret / Access Token
- LINE公式ID
- 予約、購入、プロフィール、ガチャ用 LIFF ID
- Stripe Secret Key / Publishable Key / Webhook Secret
- Stripe Price ID
- 回数券プラン
- OpenAI / Stability AI / Replicate / xAI の設定
- 公開ページ情報
- 予約、承認、当日案内、生成制限などの運用設定

## 注意

この画面は初期設定の抜け漏れを見つけるための診断画面です。実際の接続確認は、LINE Developers、Stripe Webhook、LIFF画面、画像生成テストで別途確認してください。
