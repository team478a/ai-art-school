# v5.0.4 ImageGenerationService 構文エラー修正

## 修正内容

`app/Services/ImageGenerationService.php` の `containsChildSubject()` 直後に混入していた余分なPHP断片を削除しました。

削除した断片:

```php
}
return false;
}
```

この断片により、アップデート時のPHP構文チェックで以下のファイルがエラーになっていました。

```text
app/Services/ImageGenerationService.php
```

## 影響範囲

- 画像生成サービスの構文エラーを修正
- v5.0.3までの横展開機能は維持
- 画像生成ロジック自体の仕様変更はなし

## 適用方法

管理画面のアップデートページから `aiart_update_v5.0.4_image_service_parse_fix.zip` を適用してください。
