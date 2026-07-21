# テナント完全分離監査 v5.5.7

## 対応内容

- 管理画面のユーザー、予約、開催日、出席、画像依頼、生成画像、決済、ログを現在テナントで絞り込み
- LINE Webhook、LIFF、Stripe Webhookをテナントキーで切り替え
- 画像生成キュー実行時に依頼のテナントへ切り替えてからAPIキー、LINE設定、生成制限を読み込み
- `received` のままキューがなくなった画像依頼を自動検出し、生成キューへ再登録
- cronはpending件数の事前判定をせずワーカーを起動し、孤立した依頼も復旧
- リマインド、キャンセル待ち通知、未参加・未払いフォローを全テナントごとに実行
- ガチャの参加権、結果、購入希望、管理画面リンクをテナント分離
- バックアップ・復元対象へテナント別の通知ログ、フォローログ、ガチャ関連データを追加
- 非標準テナントで `tenant_id` カラムがない場合は、他テナントのデータを返さず処理を停止
- 新規テナントが標準テナントの設定値を継承しないよう、設定プレフィックスを分離

## テナント専用設定

次の設定はクライアントごとに保存されます。

- サービス名、会社情報、規約、プライバシーポリシー
- LINE Channel Secret、Channel Access Token、LIFF ID、リッチメニュー
- Stripeキー、Webhook Secret、Price ID、料金表示
- OpenAI、Claude、Stability AI、Grok APIキーと画像生成設定
- 管理者通知先、Resend、送信元メール
- ストレージ方式、公開URL、Cloudflare R2資格情報
- 生成可能日、曜日、時間、生成数、教室運営フロー

## 意図的に共通の設定

次はSaaS本体の共通設定として扱います。

- アプリ本体URLの互換設定 `app_url`、`base_url`、`site_url`
- 共通cron起動トークン
- アップデート機構とバージョン

テナント固有の公開URLには `public_base_url` を使用してください。

## 必須URL

`{tenant_key}` はクライアント管理画面に表示されるキーです。

- LINE Webhook: `/webhook/line/{tenant_key}`
- Stripe Webhook: `/stripe/webhook/{tenant_key}`
- 予約LIFF Endpoint URL: `/liff/calendar?tenant={tenant_key}`
- 画像生成LIFF Endpoint URL: `/liff/generate?tenant={tenant_key}`
- 購入LIFF Endpoint URL: `/liff/shop?tenant={tenant_key}`
- ガチャLIFF Endpoint URL: `/liff/gacha?tenant={tenant_key}`

共通URLからテナントキーを省略すると、標準テナントとして処理されます。

## サーバー確認

1. v5.5.7をアップデート画面から適用する
2. PHP構文チェックが全件成功することを確認する
3. クライアントAへ切り替え、ユーザー、カレンダー、依頼一覧を確認する
4. クライアントBへ切り替え、Aのデータが表示されないことを確認する
5. AとBで同じLINE表示名のユーザーを登録し、それぞれ別ユーザーとして保存されることを確認する
6. 各テナントのLINE Webhook、LIFF、Stripe接続テストを行う
7. 各テナントで画像を1件ずつ生成し、正しいAPIキーとLINE公式アカウントが使われることを確認する
8. リマインド、キャンセル待ち、フォロー通知が別テナントへ送られないことを確認する

## 残る運用上の注意

- 生成画像ファイルは現在も共通の `uploads/images/{request_id}` 配下です。DBアクセスはテナント分離され、依頼IDも全体で一意ですが、物理フォルダをテナント別に分ける場合は既存画像の移行が必要です。
- 管理者メールアドレスはシステム全体で重複不可です。同じ人物が複数テナントを管理する場合は、オーナーのテナント切替機能を使用します。
- LINE公式アカウント、LIFF、Stripe、AI APIはクライアント所有の情報を各テナントへ登録してください。
