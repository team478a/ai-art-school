# SYSTEM_ANALYSIS_AIアート教室

## 文書の目的

本書は、AIアート教室システムと、千ノ国パスポート、代理店システム、ショッピングシステム、ウォレット等の他システムとの連携可否を確認するための標準分析資料である。

記載内容は、対象リポジトリのソースコードから確認できた事実を優先し、以下を区別する。

- **確認済み**：ソースコード上で実装を確認できた事項
- **未確認**：リポジトリ内の設定・スキーマ・実環境情報が不足している事項
- **要接続テスト**：コードは存在するが、実環境での疎通・整合性確認が必要な事項
- **未実装**：該当する変数、カラム、API、画面または処理を確認できない事項

---

## 連携可否サマリー

| 連携対象 | 判定 | 概要 |
|---|---:|---|
| LINE Messaging API / LIFF | ◎ | LINE Webhook、LIFF、LINE IDトークン検証、メッセージ送信処理が存在する。ただし画像生成LIFFの認証処理に重大な不備がある。 |
| 共通ID基盤 | ○ | `common_user_id` の照合、保存、Outbox再送、HMAC署名処理が存在する。接続先APIとの契約・実環境疎通が必要。 |
| 紹介・代理店紐づけ | ○ | `referral_token` を外部APIへ送り、紹介者・販売担当・現在担当代理店コードを保存できる。`agency_id` とクロージング担当者IDは未実装。 |
| ショッピングシステム | ○ | HMAC署名Webhook、決済投影、利用権投影、冪等受信の基盤が存在する。商品コード・イベント契約の接続テストが必要。 |
| ローカルStripe決済 | ◎ | Stripe Webhookとローカル決済処理が存在する。ショッピング移行時は二重付与防止が必要。 |
| 千ノ国ウォレット・ポイント | △ | ポイント残高・取引履歴・付与APIを確認できない。 |
| 管理者SSO | △ | 共通IDによる管理者SSOは未実装。 |
| 代理店報酬計算 | △ | 紹介・販売担当コードは保持できるが、報酬計算、階層、締め、取消処理は存在しない。 |
| 総合判定 | ！ | 技術的な連携基盤はあるが、LIFFのLINE user IDなりすまし対策を行わず本番連携するのは危険。 |

---

## 重要ID・変数・カラム対応表

| 業務項目 | 実際の変数名・カラム名・API名 | 状態 | 補足 |
|---|---|---:|---|
| 内部ユーザーID | `users.id` / PHP変数 `$userId` / 連携側 `local_user_id` | 確認済み | AIアート教室内部の数値ID。 |
| AIアート教室会員ID | `integration_user_mappings.ai_art_member_id` / `$aiArtMemberId` | 確認済み | `aiart:{tenant_key}:{project_key}:{local_user_id}` 形式。 |
| 共通ユーザーID | `integration_user_mappings.common_user_id` / `integration_payment_projections.common_user_id` / `integration_entitlement_projections.common_user_id` | 確認済み | `POST /api/common-users/resolve` の応答 `common_user_id` を保存。 |
| LINE userId | `users.line_user_id` / `integration_user_mappings.line_user_id` / `image_requests.line_user_id` / `$lineUserId` | 確認済み | APIペイロードでは `line_user_id`、LIFF画面からは `lineUserId` が使われる箇所がある。 |
| agency_id | 該当カラムなし | 未実装 | 数値またはUUIDの代理店マスタIDは確認できない。 |
| referral_token | LIFF入力 `referralToken` または `referral_token` / API送信 `referral_token` | 確認済み | 生トークンは外部API送信用。DBには生値を保存せず、`referral_token_hash` を保存。 |
| referral_tokenのDB保存 | `integration_referral_mappings.referral_token_hash` | 確認済み | `hash('sha256', $referralToken)`。 |
| 紹介者ID・コード | `integration_referral_mappings.registration_referrer_agent_code` | 確認済み | 数値IDではなく代理店コード。外部API応答値を保存。 |
| 販売担当者ID・コード | `integration_referral_mappings.sales_agent_code` | 確認済み | 数値IDではなく販売担当者コード。 |
| 現在担当代理店 | `integration_referral_mappings.assigned_agent_code` | 確認済み | 現在の担当代理店コードとして使用可能。 |
| クロージング担当者ID | 該当カラムなし | 未実装 | `closing_agent_id`、`closer_id`、`closing_agent_code` 等は確認できない。 |
| テナントID | `tenant_id` | 確認済み | 連携系テーブルで保持。既存テーブルはカラム有無を動的判定する実装がある。 |
| テナントキー | `tenant_key` | 確認済み | Webhook URL、連携ペイロード、設定分離に使用。 |
| プロジェクトキー | `project_key` / 設定 `integration_project_key` | 確認済み | 既定値 `ai-art-school`。 |

---

# 1. 対象リポジトリ・ブランチ・確認時点

| 項目 | 内容 |
|---|---|
| 対象リポジトリ | `team478a/ai-art-school` |
| 対象ブランチ | `main` |
| ソースコード確認コミット | `61cfb4b5da8f797548df80b236a747f4ef066dfc` |
| コミットメッセージ | `Initial import AI art school system` |
| 確認日 | 2026-07-21 |
| 分析方法 | GitHub上のPHPソース、サービス、コントローラ、連携仕様書を静的解析 |
| 制限 | 実DB、実サーバー、環境変数、外部API、LINE Developers、Stripe管理画面には接続していない |

**注意**：分析ファイル追加後のGitHubコミットは、ソースコード本体の確認コミットとは分けて扱う。

---

# 2. 技術構成

## 2.1 アプリケーション構成

- **言語**：PHP
- **フレームワーク**：Laravel等ではなく、独自MVC構成
- **エントリーポイント**：`index.php`
- **DBアクセス**：PDO
- **DB想定**：MySQL / MariaDB系
- **画面**：PHPサーバーサイドレンダリング
- **外部通信**：cURLによるHTTP API呼び出し
- **非同期処理**：DBキュー方式
  - `job_queue`
  - `integration_outbox_events`
- **マルチテナント**：`tenant_id`、`tenant_key`、`TenantScopeService`、テナント別設定
- **スキーマ管理**：一部サービスが `CREATE TABLE IF NOT EXISTS`、`ALTER TABLE`、`SHOW COLUMNS` を実行する動的スキーマ方式
- **生成画像保存**：`/uploads`
- **内部保存領域**：`/storage`

## 2.2 主な構成要素

| 種別 | 主な実装 |
|---|---|
| ルーティング | `index.php` の `switch (true)` |
| 管理者認証 | `app/Controllers/AdminAuthController.php` |
| LINE受信 | `app/Controllers/LineWebhookController.php` |
| LIFF予約 | `app/Controllers/LiffCalendarController.php` |
| LIFF画像生成 | `app/Controllers/LiffGenerateController.php` |
| 共通ID・紹介連携 | `app/Services/CommonIntegrationService.php` |
| 連携スキーマ | `app/Services/IntegrationSchemaService.php` |
| ショッピング連携 | `app/Services/ShoppingIntegrationService.php`、`ShoppingWebhookController.php` |
| Stripe連携 | `StripeService.php`、`StripeWebhookController.php` |
| LINE送信 | `LineService.php` |
| 画像生成 | `ImageGenerationService.php`、`GenerateImagesWorker.php` |
| テナント分離 | `TenantScopeService.php` |

---

# 3. 認証方式

## 3.1 管理画面認証

**確認済み**

- ログインURL：`/admin/login`
- ログアウトURL：`/admin/logout`
- 認証テーブル：`admin_users`
- ログインキー：`admin_users.email`
- パスワード：`admin_users.password_hash`
- 検証：`password_verify($password, $admin['password_hash'])`
- ログイン成功時：`session_regenerate_id(true)`
- セッションキー：
  - `$_SESSION['admin_id']`
  - `$_SESSION['admin_email']`
  - `$_SESSION['admin_name']`
  - `$_SESSION['admin_role']`
  - テナント関連セッション
- ロール：
  - `super_owner`
  - `owner`
  - `admin`
  - `staff`
- ログイン履歴：`admin_login_logs`

## 3.2 LINE Webhook認証

**確認済み**

- Webhook受信：`POST /webhook/line` または `POST /webhook/line/{tenant_key}`
- LINE署名ヘッダーを用いたHMAC検証処理が存在する。
- LINE Messaging APIのChannel Secretを使用する構成。

## 3.3 LIFF認証

**確認済み**

- LIFFから送信された `idToken` をLINEの検証APIへ送信し、LINE user IDを取得する処理が存在する。
- ただし `LiffGenerateController::request()` では、クライアント入力の `lineUserId` を先に受け入れ、`idToken` 検証成功時だけ上書きしている。
- `idToken` 検証失敗時でも `lineUserId` が空でなければ処理を継続するため、LINE user IDのなりすましが可能な状態。

## 3.4 JWT

- AIアート教室独自発行のJWTは確認できない。
- LINEの `idToken` は受け取るが、アプリ独自JWTによるセッション統合は実装されていない。

---

# 4. ユーザーID体系

## 4.1 内部ID

| ID | 実装名 | 用途 |
|---|---|---|
| ローカルユーザーID | `users.id` | AIアート教室内部の主キー |
| 連携用ローカルID | `integration_user_mappings.local_user_id` | `users.id` を共通ID連携テーブルへ保持 |
| AIアート会員ID | `ai_art_member_id` | 他システムへ渡すAIアート教室固有ID |
| LINE user ID | `line_user_id` | LINEアカウント識別 |
| 共通ユーザーID | `common_user_id` | 千ノ国各システムで共有する主ID |

## 4.2 `ai_art_member_id` の生成規則

```text
aiart:{tenant_key}:{project_key}:{local_user_id}
```

PHP実装：

```php
$aiArtMemberId = 'aiart:' . $tenantKey . ':' . $projectKey . ':' . $localUserId;
```

## 4.3 IDの正本方針

- AIアート教室内部処理：`users.id`
- LINE連携：`line_user_id`
- システム間連携：`common_user_id`
- AIアート教室側外部識別子：`ai_art_member_id`
- LINE user IDだけをシステム間の最終正本IDにしない設計となっている。

---

# 5. 共通IDに関する現在の実装

## 5.1 実装概要

**確認済み**

1. ユーザー登録・更新後に `CommonIntegrationService::registerSafely()` を呼び出す。
2. `integration_user_mappings` へローカルID、LINE user ID、AIアート会員IDを保存する。
3. `integration_outbox_events` に `common_user.resolve` イベントを登録する。
4. ワーカー・cronが外部APIへ送信する。
5. 応答の `common_user_id` を `integration_user_mappings.common_user_id` に保存する。
6. 外部API停止時でもローカル登録は成功させるフェイルオープン方式。

## 5.2 共通ID照合API

| 項目 | 内容 |
|---|---|
| HTTPメソッド | `POST` |
| APIパス | `/api/common-users/resolve` |
| ベースURL設定 | `integration_common_id_base_url` |
| 有効化設定 | `integration_enabled` |
| プロジェクト設定 | `integration_project_key` |
| Key ID | `integration_key_id` |
| HMAC Secret | `integration_hmac_secret` |
| タイムアウト | `integration_timeout_seconds` |

送信ペイロード：

```json
{
  "tenant_key": "...",
  "project_key": "ai-art-school",
  "ai_art_member_id": "aiart:...",
  "line_user_id": "U..."
}
```

受信期待値：

```json
{
  "common_user_id": "..."
}
```

## 5.3 署名ヘッダー

```text
Content-Type: application/json
X-Sengoku-Key-Id: {key_id}
X-Sengoku-Timestamp: {unix_timestamp}
X-Sengoku-Signature: {hmac_sha256}
Idempotency-Key: {event_id}
```

署名対象：

```text
{timestamp}.{raw_body}
```

## 5.4 再送

- 最大8回
- `pending` → `processing` → `completed`
- 失敗時は `retry`、上限到達時は `failed`
- 指数バックオフ
- 完了後はOutboxの `payload_json` を `{}` に置き換える。

---

# 6. 代理店・紹介者紐づけの実装

## 6.1 紹介トークン受け取り

画像生成LIFFでは以下の入力名を受け付ける。

```php
$payload['referralToken']
$payload['referral_token']
```

内部変数：

```php
$referralToken
```

## 6.2 DB保存

生の紹介トークンはDBへ保存せず、SHA-256ハッシュを保存する。

```php
$tokenHash = hash('sha256', $referralToken);
```

保存先：

```text
integration_referral_mappings.referral_token_hash
```

## 6.3 紹介確認API

| 項目 | 内容 |
|---|---|
| HTTPメソッド | `POST` |
| APIパス | `/api/referrals/confirm` |
| Outboxイベント | `referral.confirm` |
| 送信項目 | `tenant_key`、`project_key`、`ai_art_member_id`、`line_user_id`、`referral_token` |

## 6.4 外部API応答から保存する項目

| 業務項目 | 実カラム | 状態 |
|---|---|---:|
| 登録時紹介者 | `registration_referrer_agent_code` | 確認済み |
| 販売担当者 | `sales_agent_code` | 確認済み |
| 現在担当代理店 | `assigned_agent_code` | 確認済み |
| agency_id | 該当なし | 未実装 |
| クロージング担当者ID | 該当なし | 未実装 |
| 代理店組織階層 | 該当なし | 未実装 |
| 代理店報酬 | 該当なし | 未実装 |

## 6.5 現状の位置づけ

AIアート教室は代理店マスタや紹介関係の正本を持たず、外部の共通ID・代理店システムから返された結果を投影保存する構成である。

---

# 7. 登録・ログイン時のデータフロー

## 7.1 LINE Webhook経由

```text
LINEユーザー
  ↓
POST /webhook/line または /webhook/line/{tenant_key}
  ↓
LINE署名検証
  ↓
usersをLINE user IDで検索・登録・更新
  ↓
必要に応じて会話状態をuser_sessionsへ保存
  ↓
CommonIntegrationService::registerSafely()
  ↓
integration_user_mappings / integration_outbox_events
  ↓
cron・ワーカー
  ↓
POST /api/common-users/resolve
```

## 7.2 LIFF画像生成経由

```text
LIFF画面
  ↓
inputText、lineUserId、displayName、pictureUrl、idToken、referralTokenを送信
  ↓
LiffGenerateController::request()
  ↓
LINE IDトークン検証
  ↓
usersをupsert
  ↓
共通ID・紹介連携イベント登録
  ↓
image_requests作成
  ↓
job_queue作成
  ↓
GenerateImagesWorker
  ↓
外部画像生成API
  ↓
/uploadsへ保存
  ↓
LINE Messaging APIで画像送信
```

**セキュリティ注意**：現在はIDトークン検証失敗時でもクライアント送信の `lineUserId` を使用できるため、修正必須。

## 7.3 LIFF予約経由

```text
LIFF予約画面
  ↓
LINE IDトークン検証
  ↓
users照合・登録
  ↓
class_schedulesの定員確認
  ↓
class_attendancesまたはclass_waitlistsを更新
  ↓
共通ID連携イベント登録
```

## 7.4 管理者ログイン

```text
POST /admin/login
  ↓
admin_users.emailで検索
  ↓
password_verify()
  ↓
status確認
  ↓
session_regenerate_id(true)
  ↓
admin_id、admin_role、tenant情報をPHPセッションへ保存
  ↓
admin_login_logsへ記録
```

---

# 8. DBテーブルと重要カラム

## 8.1 ユーザー・管理者

| テーブル | 重要カラム | 用途 |
|---|---|---|
| `users` | `id`, `line_user_id`, `display_name`, `picture_url`, `status`, `created_at`, `updated_at` | 一般利用者 |
| `admin_users` | `id`, `email`, `password_hash`, `name`, `role`, `status`, `last_login_at` | 管理者認証 |
| `admin_login_logs` | 管理者ID、メール、結果、失敗理由、日時等 | ログイン監査 |
| `user_sessions` | LINEユーザー、会話状態、状態データ等 | LINE会話・アンケート状態 |

`users.tenant_id` 等については、`TenantScopeService` がカラムの存在を確認して条件を付与する実装があるため、実DBスキーマ確認が必要。

## 8.2 教室・予約・生成

| テーブル | 主な用途 |
|---|---|
| `class_schedules` | 開催枠・定員・日時 |
| `class_attendances` | 予約・出席・チェックイン |
| `class_waitlists` | キャンセル待ち |
| `image_requests` | 画像生成依頼 |
| `job_queue` | 画像生成等のジョブキュー |

## 8.3 共通ID・紹介連携

### `integration_user_mappings`

| カラム | 用途 |
|---|---|
| `id` | 主キー |
| `tenant_id` | テナントID |
| `local_user_id` | `users.id` |
| `ai_art_member_id` | AIアート教室固有会員ID |
| `common_user_id` | 共通ユーザーID |
| `line_user_id` | LINE user ID |
| `project_key` | プロジェクト識別子 |
| `status` | `pending` / `resolved` / `retry` / `failed` 等 |
| `response_json` | 外部API応答 |
| `last_error` | 最終エラー |
| `last_attempt_at` | 最終試行日時 |
| `resolved_at` | 解決日時 |
| `created_at`, `updated_at` | 監査日時 |

### `integration_referral_mappings`

| カラム | 用途 |
|---|---|
| `tenant_id` | テナントID |
| `local_user_id` | `users.id` |
| `referral_token_hash` | 紹介トークンのSHA-256 |
| `registration_referrer_agent_code` | 登録時紹介者コード |
| `sales_agent_code` | 販売担当者コード |
| `assigned_agent_code` | 現在担当代理店コード |
| `status` | 照合状態 |
| `confirmed_at` | 確定日時 |
| `last_error` | 最終エラー |

### `integration_outbox_events`

| カラム | 用途 |
|---|---|
| `event_id` | 冪等イベントID |
| `event_type` | `common_user.resolve` / `referral.confirm` |
| `aggregate_type` | 集約種別 |
| `aggregate_id` | ローカルユーザーID等 |
| `payload_json` | 送信内容 |
| `status` | 配送状態 |
| `attempts` | 試行回数 |
| `available_at` | 次回送信可能日時 |

## 8.4 ショッピング連携

| テーブル | 重要カラム・用途 |
|---|---|
| `integration_inbox_events` | `tenant_key`, `event_id`, `event_type`, `source`, `payload_json`, `status`, `received_at`, `processed_at`。Webhook冪等受信。 |
| `integration_payment_projections` | `order_id`, `common_user_id`, `status`, `amount`, `currency`, `paid_at`, `refunded_at`。決済状態の投影。 |
| `integration_entitlement_projections` | `entitlement_id`, `local_user_id`, `common_user_id`, `entitlement_type`, `product_code`, `quantity`, `status`, `valid_from`, `valid_until`。利用権投影。 |

## 8.5 その他

- `tenants`
- `tenant_settings`
- `system_settings`
- ガチャ関連 `gacha_*` テーブル群

ガチャ関連の全テーブル名・全カラムは、本資料作成時点では完全な一覧化をしていない。

---

# 9. API・Webhook一覧

## 9.1 外部からAIアート教室へ

| メソッド | パス | 用途 | 認証・検証 |
|---|---|---|---|
| `POST` | `/webhook/line` | LINEイベント受信 | LINE署名検証 |
| `POST` | `/webhook/line/{tenant_key}` | テナント別LINEイベント受信 | LINE署名検証 |
| `POST` | `/stripe/webhook` | Stripeイベント受信 | Stripe署名検証 |
| `POST` | `/stripe/webhook/{tenant_key}` | テナント別Stripeイベント受信 | Stripe署名検証 |
| `GET` | `/stripe/webhook` | 診断表示 | 公開診断 |
| `POST` | `/shopping/webhook` | ショッピングイベント受信 | HMAC、Key ID、時刻検証、冪等処理 |
| `POST` | `/shopping/webhook/{tenant_key}` | テナント別ショッピングイベント受信 | HMAC、Key ID、時刻検証、冪等処理 |
| `GET` | `/shopping/webhook` | ショッピング接続診断 | 公開診断 |

## 9.2 LIFF関連

| メソッド | パス | 用途 |
|---|---|---|
| `GET` | `/liff/paid` | 決済完了案内 |
| `POST` | `/liff/profile/me` | 現在ユーザー情報取得 |
| `POST` | `/liff/profile/save` | プロフィール保存 |
| `GET` | `/liff/survey` | アンケート画面 |
| `POST` | `/liff/survey/submit` | アンケート登録 |
| `POST` | `/liff/reserve` | 教室予約 |
| `POST` | `/liff/waitlist/status` | キャンセル待ち状態取得 |
| `POST` | `/liff/waitlist/cancel` | キャンセル待ち取消 |
| `POST` | `/liff/reservation/status` | 予約状態取得 |
| `POST` | `/liff/reservation/cancel` | 予約取消 |
| `POST` | 画像生成LIFFのリクエストルート | 画像生成依頼 |

画像生成LIFFの正確な公開パスは `index.php` の全ルートと画面側JavaScriptを合わせて再確認する必要がある。

## 9.3 AIアート教室から外部へ

| メソッド | API | 用途 |
|---|---|---|
| `POST` | `{integration_common_id_base_url}/api/common-users/resolve` | 共通ID照合 |
| `POST` | `{integration_common_id_base_url}/api/referrals/confirm` | 紹介トークン確認 |
| `POST` | LINE Messaging API | Reply / Pushメッセージ、画像送信 |
| `POST` | LINE IDトークン検証API | LIFFユーザー検証 |
| `POST` | OpenAI API | プロンプト補助等 |
| `POST` | Stability AI API | 画像生成 |
| `POST` | xAI / Grok系API | 設定に応じた生成処理候補 |
| `POST` | Stripe API | Checkout・決済関連 |

## 9.4 ショッピング受信イベント

```text
payment.succeeded
payment.failed
payment.refunded
entitlement.granted
entitlement.updated
entitlement.revoked
```

決済イベントは履歴参照用であり、利用権変更の正本は `entitlement.*` イベントとする設計。

## 9.5 Stripe受信イベント

```text
checkout.session.completed
customer.subscription.deleted
invoice.payment_failed
```

---

# 10. Cookie・セッション・JWT

## 10.1 PHPセッション

**確認済み**

- 管理者認証はPHPセッションを使用。
- ログイン成功時に `session_regenerate_id(true)` を実行。
- CSRFトークンもセッションに保存する構成。
- 主なセッション値：
  - `admin_id`
  - `admin_email`
  - `admin_name`
  - `admin_role`
  - `admin_tenant_key` 等

## 10.2 Cookie

**未確認**

`config/app.php` がGitHub上で取得できず、以下を確認できない。

- `session.cookie_secure`
- `session.cookie_httponly`
- `session.cookie_samesite`
- Cookie Domain
- Cookie Path
- セッション有効期限

## 10.3 JWT

- アプリ独自JWT：確認できない。
- LINE `idToken`：LIFF認証に使用。
- システム間認証：JWTではなくHMAC署名。

---

# 11. 外部サービス

| 外部サービス | 用途 | 状態 |
|---|---|---:|
| LINE Messaging API | メッセージ、画像送信、Webhook | 確認済み |
| LINE Login / LIFF | LINEユーザー認証、予約・生成画面 | 確認済み |
| OpenAI API | プロンプト生成・補助 | 確認済み |
| Stability AI | 画像生成 | 確認済み |
| xAI / Grok | 生成エンジン候補 | 設定・コードあり、実利用要確認 |
| Stripe | ローカル決済、サブスク、Webhook | 確認済み |
| 共通ID API | 共通ID照合、紹介確認 | 実装済み・接続テスト未実施 |
| ショッピングシステム | 購入導線、決済、返金、利用権 | 実装済み・接続テスト未実施 |
| Claude API | 設定項目の存在が示唆される | 実行経路未確認 |
| Resend | メール送信設定候補 | 実行経路未確認 |
| Cloudflare R2 | 外部ストレージ設定候補 | `StorageService` はローカル保存のため実運用未確認 |

---

# 12. 他システムから受け取れるデータ

## 12.1 共通ID基盤から

- `common_user_id`
- `registration_referrer_agent_code`
- `sales_agent_code`
- `assigned_agent_code`
- API応答全体：`response_json`
- 照合状態・エラー

## 12.2 ショッピングシステムから

- `event_id`
- `event_type`
- `tenant_key`
- `order_id`
- `common_user_id`
- 決済状態
- 金額 `amount`
- 通貨 `currency`
- 支払日時 `paid_at`
- 返金日時 `refunded_at`
- `entitlement_id`
- `entitlement_type`
- `product_code`
- `quantity`
- 利用権状態
- `valid_from`
- `valid_until`

## 12.3 LINEから

- LINE user ID
- 表示名
- プロフィール画像URL
- メッセージ・フォロー等のWebhookイベント
- LIFF IDトークン

## 12.4 Stripeから

- Checkout完了情報
- サブスク停止情報
- 支払失敗情報
- Stripe customer / subscription / session等の識別情報

---

# 13. 他システムへ渡せるデータ

## 13.1 共通ID基盤へ

- `tenant_key`
- `project_key`
- `ai_art_member_id`
- `line_user_id`
- `referral_token`
- Outbox `event_id`
- `Idempotency-Key`

## 13.2 LINEへ

- テキストメッセージ
- 画像URL
- リッチメニュー関連情報
- 予約・生成・決済案内

## 13.3 ショッピングシステムへ

購入URL生成時に渡すことを想定できる情報：

- `common_user_id`
- `product_code`
- `tenant_key`
- 戻り先URL

ただし、実際の購入URLクエリ・POST項目の完全な契約は、ショッピング側実装と接続して確認する必要がある。

## 13.4 現時点で渡せない、または不足する情報

- `agency_id`
- クロージング担当者ID
- 代理店組織階層
- 報酬計算結果
- ウォレットポイント残高
- ポイント取引履歴
- 権利付与の全システム横断監査ID

---

# 14. 連携に不足している機能

## 14.1 優先度：最優先

1. **LIFF画像生成APIのLINE user IDなりすまし対策**
   - `idToken` 検証成功を必須にする。
   - クライアント送信の `lineUserId` を認証根拠にしない。
   - 検証結果の `sub` と処理対象ユーザーを一致させる。

2. **代理店ID体系の確定**
   - `agency_id` の型、正本システム、発番規則を決める。
   - 現在の `*_agent_code` と代理店マスタIDの関係を定義する。

3. **クロージング担当者の保持**
   - 例：`closing_agent_code` または `closing_agent_id`
   - 販売担当者 `sales_agent_code` と明確に分離する。

## 14.2 優先度：高

- 共通ID未解決ユーザーの購入可否と再試行画面
- 共通IDの重複・統合・退会時の同期
- 紹介関係の変更可否と履歴管理
- 外部連携状態の管理画面表示
- Outbox / Inboxの再送・失敗管理画面
- ショッピング商品コードの本番マッピング
- Stripeとショッピングの二重利用権付与防止
- 権利取消・返金・期限切れの統一処理
- システム間監査ログと相関ID

## 14.3 優先度：中

- ウォレット残高・ポイント履歴API
- 代理店報酬計算連携
- 管理者SSO
- 退会、LINEブロック、アカウント統合処理
- 共通IDによるユーザー検索・管理画面表示

---

# 15. セキュリティ上の懸念

| 優先度 | 懸念 | 内容 | 必要対応 |
|---:|---|---|---|
| 緊急 | LIFF user IDなりすまし | `LiffGenerateController::request()` がIDトークン検証失敗後もクライアント送信 `lineUserId` を受理する。 | IDトークン検証成功を必須化。 |
| 高 | CSRF除外 | `/admin/update/upload`、`/admin/update/rollback`、`/admin/update` がCSRF除外。 | 署名済み更新、再認証、CSRF、ワンタイムトークンを導入。 |
| 高 | 秘密情報のDB保存 | APIキー、HMAC Secret等を設定DBへ保存する構成だが、暗号化実装を確認できない。 | KMSまたはアプリ暗号化、表示マスク、更新監査。 |
| 高 | Cookie属性不明 | `config/app.php` が確認できず、Secure、HttpOnly、SameSite等が不明。 | 本番設定・レスポンスヘッダー確認。 |
| 高 | 管理者ログイン防御 | レート制限、アカウントロック、MFAを確認できない。 | レート制限、MFA、IP・端末監査。 |
| 中 | Stripe Webhook再送・リプレイ | 署名検証はあるが、タイムスタンプ許容差の実装確認が不足。 | Stripe公式SDK・イベントID冪等性・時刻検証確認。 |
| 中 | Host Header利用 | リクエストHostからURL生成する箇所がある場合、改ざんリスク。 | 許可ホスト固定。 |
| 中 | 公開画像 | `/uploads` 配下の生成画像が公開URLとなる。 | 推測困難名、認可、期限、削除方針、個人情報確認。 |
| 中 | 例外情報露出 | ログイン等で例外メッセージを画面へ出す経路がないか要確認。 | ユーザー向け固定文言と内部ログを分離。 |
| 中 | マルチテナント越境 | 動的に `tenant_id` カラム有無を判定するため、未対応テーブルで条件が付かない可能性。 | 全テーブル越境テスト、DB制約、Repository共通化。 |
| 中 | 動的DDL | Webリクエスト中にDDLを実行する設計はロック・権限・障害リスクがある。 | マイグレーションへ移行。 |

---

# 16. 改修時に壊してはいけない機能

1. LINE Webhookの署名検証とテナント判定
2. LINE user IDと既存 `users.id` の対応関係
3. 既存ユーザーの予約、出席、キャンセル待ち履歴
4. LIFF予約・取消・キャンセル待ち処理
5. 画像生成依頼、日次上限、参加確認、受付時間制御
6. `image_requests` と `job_queue` の対応
7. 生成完了画像のLINE送信
8. 既存Stripe決済・サブスク・回数券
9. ショッピング利用権イベントの冪等処理
10. `entitlement.granted` 等を正本とする権利付与ルール
11. `integration_user_mappings` の一意制約
12. `ai_art_member_id` の生成規則
13. `common_user_id` の既存マッピング
14. 紹介トークンを生値保存しない方針
15. Outbox再送とフェイルオープン
16. テナントAのデータがテナントBへ混入しないこと
17. 管理者RBAC
18. 管理者ログイン履歴
19. `/storage`、`/uploads`、DB設定を更新処理で上書きしないこと
20. ガチャ、アンケート、プロフィール等の既存LIFF機能

---

# 17. ソースコードから確認できなかった事項

## 17.1 リポジトリ不足

以下はコードから参照されているが、GitHub上で取得できなかった、または完全性を確認できなかった。

- `config/app.php`
- `config/database.php`
- `install.php`
- 実環境専用設定ファイル
- 本番DBスキーマ・マイグレーション履歴

## 17.2 実環境でのみ確認できる事項

- 本番ドメイン
- PHPバージョン、Webサーバー、OS
- MySQL / MariaDBバージョン
- セッションCookie属性
- cron実行間隔
- Queue Workerの常駐方式
- LINE Channel、LIFF App、Webhook URLの実設定
- Stripe Webhook Secretとイベント設定
- 共通ID APIの実URL、応答仕様、障害時挙動
- ショッピングシステムの商品コードとWebhook契約
- HMAC Secretの保管方法
- Cloudflare R2、Resend、Claude、xAIの実利用状況
- バックアップ、監視、アラート、障害復旧手順

## 17.3 業務仕様として未確定・未実装

- `agency_id` の定義
- 紹介者IDと代理店IDの違い
- 販売担当者とクロージング担当者の違い
- クロージング担当者ID
- 代理店組織・階層
- 代理店変更履歴
- 報酬計算・取消・返金時の報酬戻し
- 共通ID統合・重複解消
- 退会・削除・匿名化
- ポイント付与・取消・有効期限
- ウォレット連携
- SSO

---

# 18. 必要な改修

## Phase 1：安全化

- LIFF認証の修正
- Cookie・セッション設定の固定
- CSRF除外更新処理の防御強化
- 秘密情報の暗号化・マスク
- 管理者ログインレート制限

## Phase 2：ID・代理店契約確定

- `agency_id` を追加するか、コード方式を正式採用するか決定
- `registration_referrer_agent_code`
- `sales_agent_code`
- `assigned_agent_code`
- `closing_agent_code` または `closing_agent_id`

上記の意味、更新権限、履歴、正本システムをAPI契約書へ明記する。

## Phase 3：接続・運用

- 共通ID API接続
- ショッピングWebhook接続
- 管理画面に連携状態・再送機能を追加
- 監査ログ・相関IDを追加
- Stripeからショッピングへの段階移行

## Phase 4：ウォレット・報酬

- ポイント・ウォレットAPI
- 代理店報酬イベント
- 返金・取消連動
- 階層報酬・締め処理

---

# 19. 改修による既存機能への影響

| 改修 | 影響範囲 | リスク |
|---|---|---|
| LIFF認証必須化 | 画像生成・予約・プロフィールLIFF | LIFF設定不備のユーザーが利用できなくなる可能性 |
| 共通ID必須化 | 購入導線 | 共通ID未解決ユーザーが購入不能になる |
| agency_id追加 | DB、API、管理画面 | 既存コード型データとの二重管理 |
| クロージング担当追加 | 紹介API、DB、報酬 | 既存販売担当の意味変更は不可 |
| ショッピング移行 | Stripe、利用権、購入画面 | 二重付与・取消漏れ |
| テナント制約強化 | 全画面・全集計 | 既存データに `tenant_id` がない場合の移行が必要 |
| 動的DDL廃止 | デプロイ・更新 | マイグレーション手順の新設が必要 |

---

# 20. 接続テスト項目

## 共通ID

1. 新規LINEユーザー登録で `users.id` が作成される。
2. `integration_user_mappings.local_user_id` が `users.id` と一致する。
3. `ai_art_member_id` が規則どおり生成される。
4. `/api/common-users/resolve` へHMAC署名付きで送信される。
5. 応答 `common_user_id` が保存される。
6. 同一ユーザーの再実行で重複行が作られない。
7. API停止時もローカル登録が成功し、Outboxが `retry` になる。
8. 最大8回後に `failed` となる。

## 紹介・代理店

9. `referralToken` と `referral_token` の両方を受け取れる。
10. 生トークンがDBへ残らず `referral_token_hash` のみ保存される。
11. `/api/referrals/confirm` の応答で3つの代理店コードが保存される。
12. 不正・期限切れ・使用済みトークンを正しく拒否する。
13. 紹介者、販売担当、現在担当代理店が混同されない。
14. クロージング担当が必要な商品では、未設定を検出できる。

## LIFF認証

15. `idToken` なしの画像生成リクエストを拒否する。
16. 無効な `idToken` を拒否する。
17. `idToken.sub` と別の `lineUserId` を送った場合に拒否する。
18. 他テナントのLIFFトークンを拒否する。

## ショッピング

19. 正常署名Webhookを受理する。
20. 署名不正、Key ID不一致、許容時刻超過を拒否する。
21. 同一 `event_id` の再送で二重付与しない。
22. `payment.succeeded` だけでは利用権を付与しない。
23. `entitlement.granted` で利用権を付与する。
24. `entitlement.updated` で数量・期限を更新する。
25. `entitlement.revoked` で利用権を停止する。
26. 返金後の状態が整合する。
27. テナント越境が発生しない。

## 既存機能回帰

28. LINE登録・応答
29. 教室予約・取消・キャンセル待ち
30. 画像生成・キュー・LINE送信
31. 日次上限・参加確認
32. 管理者ログイン・RBAC・CSRF
33. Stripe既存決済
34. ガチャ
35. アンケート・プロフィール
36. バックアップ・更新・ロールバック

---

# 21. 最終評価

AIアート教室には、共通ID、紹介トークン、ショッピング決済・利用権を段階的に統合するための基盤が既に存在する。特に、Outbox / Inbox、HMAC署名、冪等イベント、テナント分離、外部障害時にローカル機能を止めない設計は、他システム連携に適している。

一方で、以下の理由により、現状のまま本番連携を開始する判定は **「！ 技術的には可能だが危険」** とする。

1. LIFF画像生成でLINE user IDのなりすましが可能。
2. `agency_id` とクロージング担当者IDが存在しない。
3. 本番Cookie、秘密情報保管、実DBスキーマを確認できない。
4. 共通ID・ショッピング双方の実環境接続テストが未実施。

LIFF認証を修正し、代理店ID契約を確定し、接続テストを完了した後は、共通ID・紹介・ショッピング連携について **「○ 軽微な改修で可能」** と評価できる。
