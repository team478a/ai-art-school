# v5.1.3 Tenant Data Foundation

## 目的

SaaS化に向けて、主要データをクライアント単位で分離できる土台を追加します。

このバージョンでは、既存のデータを壊さないことを優先し、各テーブルに `tenant_id` を追加して既定クライアントへ紐づけます。

## 追加内容

- `TenantDataService` を追加
- 主要テーブルへ `tenant_id` カラムを自動追加
- `tenant_id` 用インデックスを自動追加
- 既存データを既定クライアントへ自動紐づけ
- クライアント管理画面に「データ分離準備」の診断表を追加

## 対象テーブル

- `admin_users`
- `users`
- `class_schedules`
- `class_attendances`
- `user_sessions`
- `image_requests`
- `prompts`
- `generated_images`
- `job_queue`
- `system_logs`
- `payment_transactions`
- `payment_logs`
- `audit_logs`
- `operation_logs`
- `login_logs`
- `gacha_campaigns`
- `gacha_entries`
- `gacha_results`

存在しないテーブルはスキップします。

## 注意点

このバージョンは「分離準備」です。

一覧表示、集計、Webhook、LINE処理、画像生成処理などの完全なテナント絞り込みは、次フェーズで順番に反映します。

既存環境では、クライアント管理画面を開いた時に自動で準備処理が実行されます。
