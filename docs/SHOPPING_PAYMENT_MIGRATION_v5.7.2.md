# ショッピングシステム決済移行手順 v5.7.2

## 1. 役割分担

- ショッピングシステム: 商品、価格、注文、決済、返金の正本を管理します。
- AIアート教室: 購入画面への導線、利用権の反映、教室参加時の権利判定を行います。
- AIアート教室は、支払いイベントから直接利用権を変更しません。`entitlement.granted`、`entitlement.updated`、`entitlement.revoked` のみを利用権変更の根拠にします。
- 共通ID、紹介関係、販売担当、ポイントの仕様は、この移行では変更しません。

## 2. AIアート教室側の設定

クライアント別設定で次の値を登録します。

| 設定キー | 内容 |
| --- | --- |
| `payment_provider` | 移行後は `shopping`。従来Stripeへ戻す場合は `local_stripe` |
| `shopping_checkout_base_url` | ショッピングシステムが指定する購入開始URL |
| `shopping_key_id` | 署名鍵を識別するKey ID |
| `shopping_hmac_secret` | 両システムで共有するHMAC署名シークレット |
| `shopping_webhook_tolerance_seconds` | 署名時刻の許容差。標準は `300` 秒 |
| `shopping_product_map_json` | AIアート教室の商品キーとショッピング商品コードの対応表 |

商品対応表の例:

```json
{
  "monthly": {
    "product_code": "AIART_MONTHLY",
    "label": "月額会員",
    "display_price": "月額3,850円"
  },
  "annual": {
    "product_code": "AIART_ANNUAL",
    "label": "年額会員",
    "display_price": "年額33,000円"
  },
  "ticket_6": {
    "product_code": "AIART_TICKET_6",
    "label": "6回券",
    "display_price": "5,500円"
  },
  "one_time": {
    "product_code": "AIART_ONCE",
    "label": "1回参加",
    "display_price": "3,850円"
  }
}
```

商品コードと価格はショッピングシステム側を正本とします。AIアート教室の表示金額は案内用であり、購入金額の計算には使用しません。

## 3. Webhook設定

ショッピングシステムのWebhook送信先に次のURLを登録します。

```text
https://{AIアート教室のドメイン}/shopping/webhook/{tenant_key}
```

署名ヘッダー:

```text
X-Sengoku-Timestamp: 送信時刻のUNIX秒
X-Sengoku-Signature: v1=HMAC_SHA256
X-Sengoku-Key-Id: 設定したKey ID
```

署名対象文字列は `{timestamp}.{raw_body}` です。同一イベントIDは冪等処理され、重複して利用権を付与しません。

受信対象イベント:

- `payment.succeeded` / `payment.failed` / `payment.refunded`: 決済履歴の参照用
- `entitlement.granted`: 利用権を付与
- `entitlement.updated`: 利用権を更新
- `entitlement.revoked`: 利用権を停止

## 4. 共通ユーザーID

購入開始前に利用者へ `common_user_id` が発行済みである必要があります。未連携の利用者には購入URLを発行せず、共通ID連携エラーを表示します。

Webhookの利用権イベントにも同じ `common_user_id` を含めてください。LINE User IDだけをシステム間の正本IDとして使用しません。

## 5. 段階移行手順

1. AIアート教室とショッピングシステムをバックアップします。
2. 本更新ZIPをAIアート教室の管理画面から適用します。
3. `payment_provider` はまだ `local_stripe` のまま、ショッピング接続情報と商品対応表を登録します。
4. ショッピングシステムへWebhook URLと署名情報を登録します。
5. テスト利用者に共通ユーザーIDがあることを確認します。
6. テスト商品の購入URL発行、決済、利用権付与、重複Webhookを確認します。
7. 問題がなければ `payment_provider` を `shopping` に切り替えます。
8. LINEの「購入」「サブスク」「回数券」が購入専用LIFFを開くことを確認します。

切り戻す場合は `payment_provider` を `local_stripe` に戻します。ショッピング側で付与済みの利用権は、勝手に削除せず履歴を確認して扱ってください。

## 6. 更新時に保持されるデータ

管理画面の更新機能では、`config/db.php`、`config/installed.lock`、`storage/`、`uploads/` を上書きしません。決済移行前にもサーバーとDBのバックアップを取得してください。

## 7. 動作確認

- 月額、年額、回数券、1回払いが正しいショッピング商品へ遷移する
- URLの合計金額を改ざんしてもショッピング側の商品価格が使われる
- 共通ユーザーIDがない利用者は購入できない
- 署名不正、時刻超過、Key ID不一致のWebhookを拒否する
- 同じイベントIDを複数回送っても利用権が重複しない
- 決済成功だけでは利用権を付与せず、`entitlement.granted` で付与する
- 取消・返金後の権利変更は `entitlement.updated` または `entitlement.revoked` で反映する
- テナントAのWebhookや購入がテナントBへ反映されない
