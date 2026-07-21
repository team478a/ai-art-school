# AIアート教室 次期改修指示書

## 0. 文書情報

| 項目 | 内容 |
|---|---|
| 対象システム | AIアート教室 |
| 対象リポジトリ | `team478a/ai-art-school` |
| 対象ブランチ | `main` |
| 調査日 | 2026-07-22 |
| 前回ソース基準コミット | `61cfb4b5da8f797548df80b236a747f4ef066dfc` |
| 現在確認できた最新コミット | `0456be4129039e7077d95b6db7ab2159ff498fdd` |
| 関連分析書 | `SYSTEM_ANALYSIS_AIアート教室.md` |

---

## 1. 現在の確認結果

GitHubの `main` ブランチを確認した結果、前回ソース基準コミット以降に確認できた変更は、分析Markdownの追加のみである。

アプリケーション本体について、以下の変更コミットは確認できていない。

- LIFF認証修正
- `agency_id` または正式な代理店識別子の追加
- クロージング担当者ID・コードの追加
- 共通ID連携処理の変更
- 紹介確認API応答項目の追加
- ショッピング連携処理の変更
- 接続テストコードの追加

したがって、本指示書では前回分析で判明した不足事項を次期改修範囲として定義する。

### 現在の連携判定

| 項目 | 判定 | 理由 |
|---|---:|---|
| 共通ID連携 | ○ | 基盤は存在するが、実環境疎通が必要 |
| 紹介トークン連携 | ○ | 照合・保存基盤は存在する |
| 代理店ID連携 | △ | `agency_id` を確認できない |
| 販売担当者連携 | ○ | `sales_agent_code` が存在する |
| クロージング担当者連携 | △ | 専用カラムを確認できない |
| ショッピング連携 | ○ | Webhook・権利投影基盤は存在する |
| LIFF認証安全性 | ！ | LINE user IDなりすましの可能性がある |
| 本番総合連携 | ！ | 認証修正と接続テスト完了前の本番接続は危険 |

---

## 2. 改修の目的

AIアート教室を、千ノ国パスポート、代理店システム、ショッピングシステム、ウォレット等と安全に連携できる状態にする。

今回の最優先目的は次の4点である。

1. LIFF画像生成リクエストのLINE user IDなりすましを防止する。
2. 代理店およびクロージング担当者の正式な識別情報を保持する。
3. 共通ID・紹介確認APIの応答を欠落なく保存する。
4. 既存のLINE、予約、画像生成、決済、テナント分離を壊さず接続テスト可能にする。

---

## 3. 対象リポジトリ・作業ブランチ

### 対象リポジトリ

```text
team478a/ai-art-school
```

### ベースブランチ

```text
main
```

### 推奨作業ブランチ

```text
fix/integration-security-and-agency-fields
```

### 作業開始条件

- `main` の最新状態を取得する。
- `SYSTEM_ANALYSIS_AIアート教室.md` を確認する。
- 本指示書の対象外変更を同一コミットへ混在させない。
- DB変更前に本番相当DBのバックアップを取得する。

---

## 4. 変更範囲

主な変更対象候補は以下とする。

```text
app/Controllers/LiffGenerateController.php
app/Services/CommonIntegrationService.php
app/Services/IntegrationSchemaService.php
app/Controllers/ShoppingWebhookController.php
app/Services/ShoppingIntegrationService.php
app/Views/admin/
docs/integration/
```

必要に応じて以下も変更対象とする。

- LIFF画面側JavaScript
- 管理者ユーザー詳細画面
- マイグレーションまたは安全なスキーマ更新処理
- 自動テスト
- 接続テスト用スクリプト
- 運用・ロールバック手順書

---

## 5. 変更禁止範囲

以下は、明示的な移行設計なしに変更・削除してはならない。

1. `users.id` の既存値および主キー体系
2. `users.line_user_id` と既存ユーザーの対応関係
3. `ai_art_member_id` の生成規則
4. 既存の `common_user_id` マッピング
5. `registration_referrer_agent_code` の意味
6. `sales_agent_code` の意味
7. `assigned_agent_code` の意味
8. LINE Webhook署名検証
9. 予約・出席・キャンセル待ち処理
10. 画像生成依頼と `job_queue` の関連
11. 生成完了画像のLINE送信
12. Stripe既存決済・サブスク・回数券
13. ショッピングWebhookの冪等処理
14. `entitlement.granted` 等を利用権変更根拠とする方針
15. Outbox再送とフェイルオープン方式
16. テナント分離条件
17. 管理者RBAC
18. `/storage`、`/uploads`、実環境設定の保持
19. ガチャ、アンケート、プロフィール等の既存LIFF機能
20. 本番データを自動削除・初期化する処理

---

## 6. 必須改修1：LIFF認証の安全化

### 対象

```text
app/Controllers/LiffGenerateController.php
```

### 現在確認されている問題

`LiffGenerateController::request()` では、クライアント入力の `lineUserId` を受け取り、`idToken` の検証が成功した場合だけ検証済みIDで上書きしている。

このため、`idToken` の検証に失敗してもクライアント入力の `lineUserId` が空でなければ、処理を続行できる可能性がある。

### 必須変更

1. `idToken` を必須入力とする。
2. LINEの検証APIで `idToken` の検証が成功しない場合は処理を拒否する。
3. 認証済みLINE user IDは、検証結果の `sub` のみを使用する。
4. クライアント入力 `lineUserId` を認証根拠に使用しない。
5. `lineUserId` を互換目的で送信する場合も、検証結果と不一致なら拒否する。
6. 認証失敗時は以下を一切作成・更新しない。
   - `users`
   - `integration_user_mappings`
   - `integration_referral_mappings`
   - `image_requests`
   - `job_queue`
7. 認証失敗理由の詳細を利用者画面へ露出しない。
8. 内部ログには相関IDを付けて記録する。

### 推奨HTTP応答

| 状態 | HTTPステータス |
|---|---:|
| `idToken` 未指定 | `401` |
| 無効・期限切れ | `401` |
| LINE Channel不一致 | `403` |
| `sub` と入力 `lineUserId` 不一致 | `403` |
| 一時的なLINE検証API障害 | `503` |

---

## 7. 必須改修2：代理店・担当者ID体系

### 現在確認できる項目

```text
registration_referrer_agent_code
sales_agent_code
assigned_agent_code
```

### 不足項目

```text
agency_id
closing_agent_id
```

または、代理店システムの正式契約がコード方式の場合は以下とする。

```text
agency_code
closing_agent_code
```

### 実装前に確定する事項

1. 代理店システムの主キーが数値ID、UUID、コードのどれか。
2. `assigned_agent_code` と `agency_id` の関係。
3. `sales_agent_code` が販売担当者、紹介者、代理店組織のどれを表すか。
4. クロージング担当者が代理店ユーザーか、本部管理者か。
5. 担当者変更履歴をAIアート教室側に保持するか。
6. 外部システムを正本とし、AIアート教室側は投影のみとするか。

### 推奨方針

- 代理店システムを正本とする。
- AIアート教室では外部応答結果を参照用に保存する。
- 既存のコード項目は削除しない。
- IDとコードを両方持つ場合は意味を明確に分離する。

### DB変更例

正式な型を代理店システムに合わせること。

```sql
ALTER TABLE integration_referral_mappings
    ADD COLUMN agency_id VARCHAR(191) NULL AFTER referral_token_hash,
    ADD COLUMN closing_agent_id VARCHAR(191) NULL AFTER sales_agent_code;
```

コード方式の場合：

```sql
ALTER TABLE integration_referral_mappings
    ADD COLUMN agency_code VARCHAR(191) NULL AFTER referral_token_hash,
    ADD COLUMN closing_agent_code VARCHAR(191) NULL AFTER sales_agent_code;
```

### 追加検討項目

```text
assignment_source
assignment_version
referral_confirmed_at
agent_data_updated_at
```

---

## 8. 必須改修3：紹介確認API応答の拡張

### 対象API

```text
POST /api/referrals/confirm
```

### 現在保存している応答項目

```text
registration_referrer_agent_code
sales_agent_code
assigned_agent_code
```

### 追加する応答候補

```json
{
  "common_user_id": "CU-000001",
  "agency_id": "AG-000001",
  "registration_referrer_agent_code": "AGENT-REF-001",
  "sales_agent_code": "AGENT-SALES-001",
  "closing_agent_id": "AGENT-CLOSE-001",
  "assigned_agent_code": "AGENT-CURRENT-001",
  "status": "confirmed"
}
```

### 必須条件

1. 未知の追加項目があっても処理を停止しない。
2. 必須項目不足時は `confirmed` にしない。
3. 同一イベント再送時に重複レコードを作成しない。
4. 応答値で既存の確定済み項目を空値上書きしない。
5. 外部API応答全体は、秘密情報を除外した上で監査可能にする。
6. 紹介トークン生値を処理完了後に残さない。
7. API契約バージョンを記録できるようにする。

---

## 9. 必須改修4：管理画面の連携状態表示

### ユーザー詳細画面に表示する項目

```text
users.id
line_user_id
ai_art_member_id
common_user_id
agency_id または agency_code
registration_referrer_agent_code
sales_agent_code
closing_agent_id または closing_agent_code
assigned_agent_code
共通ID連携状態
紹介確認状態
最終連携日時
最終エラー
```

### 表示ルール

- 外部システムを正本とする項目は原則編集不可とする。
- `pending`、`resolved`、`confirmed`、`retry`、`failed` を判別可能にする。
- エラー全文に秘密情報やトークンを表示しない。
- オーナーまたは必要権限を持つ管理者だけが閲覧できるようにする。
- 再送操作を実装する場合は、CSRF対策と操作ログを必須とする。

---

## 10. 必須改修5：ショッピング連携確認

### 確認対象

```text
POST /shopping/webhook
POST /shopping/webhook/{tenant_key}
```

### 対象イベント

```text
payment.succeeded
payment.failed
payment.refunded
entitlement.granted
entitlement.updated
entitlement.revoked
```

### 必須確認

1. HMAC署名検証
2. Key ID一致
3. タイムスタンプ許容差
4. 同一 `event_id` の冪等処理
5. `common_user_id` によるユーザー特定
6. `payment.succeeded` だけで利用権を付与しないこと
7. `entitlement.granted` を付与根拠にすること
8. 取消・返金時に `entitlement.updated` または `entitlement.revoked` を反映すること
9. Stripeとショッピングの二重付与防止
10. テナント越境防止

---

## 11. 受入条件

すべて満たした場合のみ完了とする。

### 認証

- 正常なLINE IDトークンで画像生成できる。
- `idToken` 未指定の生成要求を拒否する。
- 無効・期限切れトークンを拒否する。
- 別ユーザーの `lineUserId` を送っても操作できない。
- 認証失敗時にDB・キューへ副作用が発生しない。

### 共通ID

- 新規ユーザー登録後に `integration_user_mappings` が作成される。
- `local_user_id` が `users.id` と一致する。
- `ai_art_member_id` の生成規則が変わらない。
- 共通ID API応答の `common_user_id` が保存される。
- API停止時もローカル登録が失敗しない。
- Outbox再送が最大8回の既存方針を維持する。

### 代理店・紹介

- `referral_token` を照合できる。
- 生トークンをDBへ保存しない。
- 紹介者、販売担当、クロージング担当、現在担当代理店を別項目として保持する。
- `agency_id` または正式な代理店識別子が保存される。
- 同一紹介イベント再送で重複しない。

### ショッピング

- 正常な署名Webhookを受理する。
- 不正署名、時刻超過、Key ID不一致を拒否する。
- 同一イベント再送で権利を二重付与しない。
- 返金・取消後に利用権状態が整合する。

### 既存機能

- LINE Webhookが動作する。
- 予約・取消・キャンセル待ちが動作する。
- 画像生成・キュー・LINE送信が動作する。
- 日次上限と参加確認が動作する。
- 管理者ログインとRBACが動作する。
- Stripe既存決済が動作する。
- ガチャ、アンケート、プロフィールが動作する。
- テナント間データ混入がない。

---

## 12. テスト項目

### 12.1 LIFF認証テスト

1. 正常な `idToken`
2. `idToken` 未指定
3. 不正な文字列
4. 期限切れトークン
5. 別Channelのトークン
6. `idToken.sub` と異なる `lineUserId`
7. LINE検証APIタイムアウト
8. LINE検証API 5xx
9. 認証失敗時にユーザーが作成されないこと
10. 認証失敗時に生成ジョブが作成されないこと

### 12.2 共通IDテスト

1. 新規登録
2. 既存登録
3. 同一LINE user ID再登録
4. 共通ID正常応答
5. `common_user_id` 欠落応答
6. 外部APIタイムアウト
7. 外部API 4xx / 5xx
8. Outbox再送
9. 最大試行回数到達
10. テナント別分離

### 12.3 紹介・代理店テスト

1. 正常な `referralToken`
2. 正常な `referral_token`
3. 不正トークン
4. 期限切れトークン
5. 使用済みトークン
6. 紹介者のみ存在
7. 販売担当のみ存在
8. クロージング担当のみ存在
9. 現在担当代理店変更
10. 空値応答で確定済み情報が消えないこと
11. 同一イベント再送
12. トークン生値がDB・ログに残らないこと

### 12.4 ショッピングテスト

1. `payment.succeeded`
2. `payment.failed`
3. `payment.refunded`
4. `entitlement.granted`
5. `entitlement.updated`
6. `entitlement.revoked`
7. 同一 `event_id` 再送
8. 不正署名
9. 時刻超過
10. Key ID不一致
11. `common_user_id` 不明
12. テナント不一致
13. Stripeとショッピングの二重通知

### 12.5 回帰テスト

1. LINE友だち追加・メッセージ応答
2. 教室予約
3. 予約取消
4. キャンセル待ち
5. 出席確認
6. 画像生成
7. 画像再送
8. 管理者ログイン
9. 権限別アクセス
10. Stripe月額・年額・回数券
11. ガチャ
12. アンケート
13. プロフィール
14. CSV出力
15. バックアップ・更新・ロールバック

---

## 13. ロールバック条件

以下のいずれかが発生した場合は、`main` へマージせず、または直ちに切り戻す。

1. 正常なLINEユーザーが登録できない。
2. 予約、取消、キャンセル待ちが動作しない。
3. 画像生成またはLINE画像送信が停止する。
4. `users.id` と既存ユーザーの対応が変わる。
5. `ai_art_member_id` が既存規則と異なる。
6. 既存 `common_user_id` が消失または別ユーザーへ付け替わる。
7. テナント越境が発生する。
8. Stripeまたはショッピング利用権が二重付与される。
9. 返金後も権利が残る。
10. 紹介者、販売担当、クロージング担当が混同される。
11. 紹介トークン生値がDBまたはログへ保存される。
12. 認証失敗リクエストでDB副作用が発生する。
13. 管理者権限のないユーザーが連携情報を閲覧・再送できる。

### ロールバック前提

- DB変更前バックアップを取得する。
- 追加カラムは既存カラムを削除せず追加方式とする。
- ロールバック時に新規カラムを即時削除しない。
- 新旧コードの互換期間を設ける。
- `payment_provider` を切り替えた場合は `local_stripe` へ戻せるようにする。

---

## 14. 提出物

開発完了時に以下を提出する。

1. 作業ブランチ名
2. コミットSHA
3. 変更ファイル一覧
4. 変更理由
5. DB変更SQLまたはマイグレーション
6. API契約変更内容
7. テスト結果
8. 未実施テスト
9. 未確認事項
10. ロールバック手順
11. 既存機能への影響
12. `SYSTEM_ANALYSIS_AIアート教室.md` の更新案

---

## 15. 次回GitHub確認時の確認順序

1. 前回基準コミットとの差分ファイルを確認する。
2. `LiffGenerateController.php` の認証条件を確認する。
3. DBスキーマ変更を確認する。
4. `CommonIntegrationService.php` の応答保存項目を確認する。
5. `agency_id`、クロージング担当者項目を確認する。
6. 管理画面表示と権限制御を確認する。
7. ショッピングWebhookの署名・冪等性を確認する。
8. テストコード・テスト結果を確認する。
9. 既存機能への影響を再評価する。
10. `SYSTEM_ANALYSIS_AIアート教室.md` を更新する。

---

## 16. 最終指示

最優先はLIFF認証修正である。

代理店ID、クロージング担当者、ショッピング接続等の機能追加を先に実施しても、LINE user IDを安全に確定できなければ、誤ったユーザーへ共通ID、紹介情報、利用権を紐づける危険がある。

したがって、以下の順序を厳守する。

```text
1. LIFF認証修正
2. 認証単体テスト
3. 代理店・クロージング担当項目追加
4. 共通ID・紹介API応答保存
5. 管理画面表示
6. ショッピング接続テスト
7. 全体回帰テスト
8. SYSTEM_ANALYSIS更新
```

本番接続の判定は、上記の受入条件および接続テストが完了した後に行う。