<?php
$pageTitle = 'API設定';
ob_start();

if (!function_exists('sv')) {
    function sv(string $key, array $settings, string $default = ''): string {
        return htmlspecialchars((string)($settings[$key] ?? $default), ENT_QUOTES, 'UTF-8');
    }
}

$selected = static function (array $settings, string $key, string $value, string $default = ''): string {
    return (string)($settings[$key] ?? $default) === $value ? 'selected' : '';
};

$checked = static function (array $settings, string $key, string $default = '0'): string {
    return (string)($settings[$key] ?? $default) === '1' ? 'checked' : '';
};

$ticketPlans = json_decode((string)($settings['ticket_plans'] ?? '[]'), true);
if (!is_array($ticketPlans)) {
    $ticketPlans = [];
}
for ($i = count($ticketPlans); $i < 3; $i++) {
    $ticketPlans[] = ['count' => '', 'price' => ''];
}

$engineOptions = [
    'openai' => 'OpenAI',
    'grok' => 'Grok / xAI',
    'stability' => 'Stability AI',
];
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="alert alert-success">設定を保存しました。</div>
<?php endif; ?>

<div id="test-result" style="display:none;margin-bottom:16px"></div>

<form method="POST" action="/admin/settings">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>

  <div class="responsive-grid">
    <div class="card">
      <div class="card-header">LINE Messaging API</div>
      <div class="card-body">
        <div class="form-group">
          <label>Channel Secret</label>
          <input type="text" name="line_channel_secret" id="line_channel_secret" value="<?= sv('line_channel_secret', $settings) ?>" placeholder="Channel Secret">
        </div>
        <div class="form-group">
          <label>Channel Access Token</label>
          <input type="text" name="line_channel_access_token" id="line_channel_access_token" value="<?= sv('line_channel_access_token', $settings) ?>" placeholder="Channel Access Token">
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('line')">接続テスト</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">OpenAI 写真・高品質生成</div>
      <div class="card-body">
        <div class="form-group">
          <label>OpenAI APIキー</label>
          <input type="text" name="openai_api_key" id="openai_api_key" value="<?= sv('openai_api_key', $settings) ?>" placeholder="sk-...">
          <p class="setting-help">人物、子ども、写真からのイラスト化、顔や手の品質を優先したい生成で使います。</p>
        </div>
        <div class="form-group">
          <label>画像モデル</label>
          <select name="openai_image_model">
            <option value="gpt-image-1" <?= $selected($settings, 'openai_image_model', 'gpt-image-1', 'gpt-image-1') ?>>gpt-image-1</option>
          </select>
        </div>
        <div class="form-group">
          <label>OpenAI品質</label>
          <select name="openai_image_quality">
            <option value="high" <?= $selected($settings, 'openai_image_quality', 'high', 'high') ?>>高品質</option>
            <option value="medium" <?= $selected($settings, 'openai_image_quality', 'medium') ?>>標準</option>
            <option value="low" <?= $selected($settings, 'openai_image_quality', 'low') ?>>低コスト</option>
          </select>
          <p class="setting-help">顔崩れを避けたい場合は「高品質」を推奨します。</p>
        </div>
        <div class="form-group">
          <label>写真イラスト化の出力サイズ</label>
          <select name="photo_illustration_size">
            <option value="1024x1024" <?= $selected($settings, 'photo_illustration_size', '1024x1024', '1024x1024') ?>>1024x1024</option>
            <option value="1024x1536" <?= $selected($settings, 'photo_illustration_size', '1024x1536') ?>>1024x1536</option>
            <option value="1536x1024" <?= $selected($settings, 'photo_illustration_size', '1536x1024') ?>>1536x1024</option>
          </select>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('openai')">接続テスト</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">画像生成エンジン</div>
      <div class="card-body">
        <div class="form-group">
          <label>通常の生成エンジン</label>
          <select name="image_engine">
            <?php foreach ($engineOptions as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selected($settings, 'image_engine', $value, 'stability') ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>人物・子ども向け安全エンジン</label>
          <select name="image_human_safe_engine">
            <?php foreach ($engineOptions as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selected($settings, 'image_human_safe_engine', $value, 'openai') ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <p class="setting-help">顔や手の崩れを減らすため、人物・子どもが含まれる依頼ではこのエンジンを優先します。</p>
        </div>
        <div class="form-group">
          <label>高品質生成エンジン</label>
          <select name="image_high_quality_engine">
            <?php foreach ($engineOptions as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selected($settings, 'image_high_quality_engine', $value, 'openai') ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>品質方針</label>
          <select name="image_quality_level">
            <option value="premium" <?= $selected($settings, 'image_quality_level', 'premium', 'premium') ?>>標準・安定</option>
            <option value="max" <?= $selected($settings, 'image_quality_level', 'max') ?>>最高品質を優先</option>
          </select>
          <p class="setting-help">最高品質を優先すると、人物表現をより丁寧にします。処理時間とAPI費用は増える場合があります。</p>
        </div>
        <div class="form-group">
          <label>画像の向き</label>
          <select name="image_aspect">
            <option value="square" <?= $selected($settings, 'image_aspect', 'square', 'square') ?>>正方形</option>
            <option value="portrait" <?= $selected($settings, 'image_aspect', 'portrait') ?>>縦長</option>
            <option value="landscape" <?= $selected($settings, 'image_aspect', 'landscape') ?>>横長</option>
          </select>
        </div>
        <div class="form-group">
          <label>禁止ワード・避けたい表現</label>
          <textarea name="ng_words" rows="4" placeholder="例：ホラー、怖い顔、崩れた顔、余分な指"><?= sv('ng_words', $settings) ?></textarea>
          <p class="setting-help">ここに入れた語句はネガティブ指定にも反映されます。</p>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Stability AI</div>
      <div class="card-body">
        <div class="form-group">
          <label>APIキー</label>
          <input type="text" name="stability_api_key" id="stability_api_key" value="<?= sv('stability_api_key', $settings) ?>" placeholder="sk-...">
        </div>
        <div class="form-group">
          <label>モデル</label>
          <select name="stability_model">
            <option value="sdxl" <?= $selected($settings, 'stability_model', 'sdxl', 'sdxl') ?>>SDXL</option>
            <option value="core" <?= $selected($settings, 'stability_model', 'core') ?>>Stable Image Core</option>
            <option value="ultra" <?= $selected($settings, 'stability_model', 'ultra') ?>>Stable Image Ultra</option>
          </select>
        </div>
        <div class="settings-two">
          <div class="form-group">
            <label>生成ステップ数</label>
            <select name="image_steps">
              <?php foreach (['20', '30', '40', '50'] as $value): ?>
                <option value="<?= $value ?>" <?= $selected($settings, 'image_steps', $value, '30') ?>><?= $value ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>CFG Scale</label>
            <select name="image_cfg">
              <?php foreach (['5', '7', '9', '12'] as $value): ?>
                <option value="<?= $value ?>" <?= $selected($settings, 'image_cfg', $value, '7') ?>><?= $value ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="settings-two">
          <div class="form-group">
            <label>残高不足時の自動切替</label>
            <select name="stability_auto_switch_enabled">
              <option value="1" <?= $selected($settings, 'stability_auto_switch_enabled', '1', '1') ?>>有効</option>
              <option value="0" <?= $selected($settings, 'stability_auto_switch_enabled', '0') ?>>無効</option>
            </select>
          </div>
          <div class="form-group">
            <label>切替しきい値</label>
            <input type="number" step="0.1" name="stability_auto_switch_threshold" value="<?= sv('stability_auto_switch_threshold', $settings, '1') ?>" min="0">
          </div>
        </div>
        <div class="form-group">
          <label>フォールバック先</label>
          <select name="stability_fallback_engine">
            <option value="openai" <?= $selected($settings, 'stability_fallback_engine', 'openai', 'openai') ?>>OpenAI</option>
            <option value="grok" <?= $selected($settings, 'stability_fallback_engine', 'grok') ?>>Grok / xAI</option>
          </select>
          <p class="setting-help">Stabilityの残高不足、429、5xx、タイムアウト時はこのエンジンへ自動切替します。</p>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('stability')">接続テスト</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Grok / xAI</div>
      <div class="card-body">
        <div class="form-group">
          <label>APIキー</label>
          <input type="text" name="grok_api_key" id="grok_api_key" value="<?= sv('grok_api_key', $settings) ?>" placeholder="xai-...">
        </div>
        <div class="form-group">
          <label>画像モデル</label>
          <input type="text" name="grok_image_model" value="<?= sv('grok_image_model', $settings, 'grok-imagine-image') ?>">
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('grok')">接続テスト</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Claude API</div>
      <div class="card-body">
        <div class="form-group">
          <label>APIキー</label>
          <input type="text" name="claude_api_key" id="claude_api_key" value="<?= sv('claude_api_key', $settings) ?>" placeholder="sk-ant-...">
        </div>
        <div class="form-group">
          <label>プロンプト生成モデル</label>
          <select name="prompt_model">
            <option value="haiku" <?= $selected($settings, 'prompt_model', 'haiku', 'haiku') ?>>Haiku</option>
            <option value="sonnet" <?= $selected($settings, 'prompt_model', 'sonnet') ?>>Sonnet</option>
          </select>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('claude')">接続テスト</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Stripe決済</div>
      <div class="card-body">
        <div class="form-group">
          <label>シークレットキー</label>
          <input type="text" name="stripe_secret_key" id="stripe_secret_key" value="<?= sv('stripe_secret_key', $settings) ?>" placeholder="sk_live_...">
        </div>
        <div class="form-group">
          <label>公開可能キー</label>
          <input type="text" name="stripe_publishable_key" value="<?= sv('stripe_publishable_key', $settings) ?>" placeholder="pk_live_...">
        </div>
        <div class="form-group">
          <label>Webhook署名シークレット</label>
          <input type="text" name="stripe_webhook_secret" value="<?= sv('stripe_webhook_secret', $settings) ?>" placeholder="whsec_...">
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('stripe')">接続テスト</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">料金・購入プラン設定</div>
      <div class="card-body">
        <div class="form-group">
          <label>月額サブスク Price ID</label>
          <input type="text" name="stripe_subscription_price_id" value="<?= sv('stripe_subscription_price_id', $settings) ?>" placeholder="price_...">
          <p class="setting-help">Stripeの商品価格で作成した「毎月」のPrice IDを入力します。</p>
        </div>
        <div class="form-group">
          <label>月額サブスク 表示料金</label>
          <input type="text" name="subscription_price_label" value="<?= sv('subscription_price_label', $settings) ?>" placeholder="例：月額3850円（税込）">
        </div>
        <div class="form-group">
          <label>年額サブスク Price ID</label>
          <input type="text" name="stripe_annual_subscription_price_id" value="<?= sv('stripe_annual_subscription_price_id', $settings) ?>" placeholder="price_...">
          <p class="setting-help">Stripeの商品価格で作成した「毎年」のPrice IDを入力します。</p>
        </div>
        <div class="form-group">
          <label>年額サブスク 表示料金</label>
          <input type="text" name="annual_subscription_price_label" value="<?= sv('annual_subscription_price_label', $settings) ?>" placeholder="例：年額33000円（税込）">
        </div>
        <div class="form-group">
          <label>回数券の有効日数</label>
          <input type="number" name="ticket_valid_days" value="<?= sv('ticket_valid_days', $settings, '180') ?>" min="1">
        </div>
        <div class="form-group">
          <label>回数券プラン</label>
          <div class="settings-plan-list">
            <?php foreach (array_slice($ticketPlans, 0, 3) as $plan): ?>
              <div class="settings-two">
                <input type="number" name="ticket_count[]" value="<?= htmlspecialchars((string)($plan['count'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="回数 例：6" min="0">
                <input type="number" name="ticket_price[]" value="<?= htmlspecialchars((string)($plan['price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="金額 例：5500" min="0">
              </div>
            <?php endforeach; ?>
          </div>
          <p class="setting-help">回数と金額の両方が入っている行だけ購入画面に表示されます。金額は税込の円で入力してください。</p>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">利用制限</div>
      <div class="card-body">
        <div class="form-group">
          <label>1ユーザーあたりの1日最大依頼数</label>
          <input type="number" name="max_daily_requests_per_user" value="<?= sv('max_daily_requests_per_user', $settings, '2') ?>" min="1">
        </div>
        <div class="form-group">
          <label>1依頼あたりの最大生成枚数</label>
          <input type="number" name="max_images_per_request" value="<?= sv('max_images_per_request', $settings, '8') ?>" min="1">
        </div>
        <div class="form-group">
          <label>1パターンあたりの生成枚数</label>
          <select name="images_per_pattern">
            <?php foreach (['1', '2', '3', '4'] as $value): ?>
              <option value="<?= $value ?>" <?= $selected($settings, 'images_per_pattern', $value, '4') ?>><?= $value ?>枚</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group checkbox-row">
          <label><input type="checkbox" name="line_grid_mode" value="1" <?= $checked($settings, 'line_grid_mode') ?>> LINE送信用に画像をまとめる</label>
        </div>
        <div class="form-group">
          <label>LINE月間送信上限</label>
          <input type="number" name="line_monthly_limit" value="<?= sv('line_monthly_limit', $settings, '5000') ?>" min="0">
        </div>
        <div class="form-group">
          <label>生成申請の受付方式</label>
          <select name="generation_access_mode">
            <option value="class_attendance" <?= $selected($settings, 'generation_access_mode', 'class_attendance', 'class_attendance') ?>>教室参加確認後のみ</option>
            <option value="time_window_only" <?= $selected($settings, 'generation_access_mode', 'time_window_only') ?>>受付時間内なら生成可能（予約不要）</option>
            <option value="class_or_time_window" <?= $selected($settings, 'generation_access_mode', 'class_or_time_window') ?>>参加確認済み、または受付時間内</option>
            <option value="always_open" <?= $selected($settings, 'generation_access_mode', 'always_open') ?>>常時受付</option>
          </select>
          <p class="setting-help">教室予約を使わず「何時から何時まで生成申請できる」という運用にする場合は、受付時間内なら生成可能を選びます。</p>
        </div>
        <div class="settings-two">
          <div class="form-group">
            <label>生成受付開始</label>
            <input type="time" name="generation_window_start" value="<?= sv('generation_window_start', $settings) ?>">
          </div>
          <div class="form-group">
            <label>生成受付終了</label>
            <input type="time" name="generation_window_end" value="<?= sv('generation_window_end', $settings) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>受付時間外メッセージ</label>
          <textarea name="generation_window_message" rows="3" placeholder="未入力の場合は標準メッセージを表示します。"><?= sv('generation_window_message', $settings) ?></textarea>
        </div>
        <div class="form-group checkbox-row">
          <label><input type="checkbox" name="generation_online_enabled" value="1" <?= $checked($settings, 'generation_online_enabled', '1') ?>> オンライン生成を有効にする</label>
          <p class="setting-help">予約や教室参加とは別に、LINE上で自動生成を受け付ける運用に使います。</p>
        </div>
        <div class="settings-two">
          <div class="form-group">
            <label>生成可能開始日</label>
            <input type="date" name="generation_available_date_start" value="<?= sv('generation_available_date_start', $settings) ?>">
          </div>
          <div class="form-group">
            <label>生成可能終了日</label>
            <input type="date" name="generation_available_date_end" value="<?= sv('generation_available_date_end', $settings) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>生成可能曜日</label>
          <input type="text" name="generation_available_weekdays" value="<?= sv('generation_available_weekdays', $settings) ?>" placeholder="例：mon,tue,wed,thu,fri">
          <p class="setting-help">未入力なら全曜日で受付します。sun,mon,tue,wed,thu,fri,sat または 0〜6（日〜土）をカンマ区切りで入力します。</p>
        </div>
        <div class="form-group">
          <label>期間内の1ユーザー最大生成依頼数</label>
          <input type="number" name="generation_period_request_limit" value="<?= sv('generation_period_request_limit', $settings, '0') ?>" min="0">
          <p class="setting-help">0または未入力なら期間内上限は使いません。開始日・終了日と組み合わせてキャンペーン期間の生成数を制御できます。</p>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">通知</div>
      <div class="card-body">
        <div class="form-group">
          <label>管理者LINEユーザーID</label>
          <input type="text" name="admin_line_user_id" value="<?= sv('admin_line_user_id', $settings) ?>" placeholder="Uxxxxxxxx...">
        </div>
        <div class="form-group">
          <label>管理者メールアドレス</label>
          <input type="email" name="admin_email" value="<?= sv('admin_email', $settings) ?>" placeholder="admin@example.com">
        </div>
        <div class="form-group checkbox-row">
          <label><input type="checkbox" name="admin_notify_email" value="1" <?= $checked($settings, 'admin_notify_email') ?>> 管理者へメール通知する</label>
        </div>
        <div class="form-group">
          <label>Resend APIキー</label>
          <input type="text" name="resend_api_key" value="<?= sv('resend_api_key', $settings) ?>" placeholder="re_...">
        </div>
        <div class="form-group">
          <label>送信元メールアドレス</label>
          <input type="email" name="mail_from" value="<?= sv('mail_from', $settings) ?>" placeholder="noreply@example.com">
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">ストレージ</div>
      <div class="card-body">
        <div class="form-group">
          <label>ストレージ方式</label>
          <select name="storage_driver">
            <option value="local" <?= $selected($settings, 'storage_driver', 'local', 'local') ?>>ローカル</option>
            <option value="r2" <?= $selected($settings, 'storage_driver', 'r2') ?>>Cloudflare R2</option>
          </select>
        </div>
        <div class="form-group">
          <label>公開URL</label>
          <input type="text" name="storage_public_url" value="<?= sv('storage_public_url', $settings) ?>" placeholder="https://cdn.example.com">
        </div>
        <details>
          <summary>Cloudflare R2設定</summary>
          <div class="details-body">
            <div class="form-group"><label>Account ID</label><input type="text" name="r2_account_id" value="<?= sv('r2_account_id', $settings) ?>"></div>
            <div class="form-group"><label>Bucket Name</label><input type="text" name="r2_bucket" value="<?= sv('r2_bucket', $settings) ?>"></div>
            <div class="form-group"><label>Access Key ID</label><input type="text" name="r2_access_key" value="<?= sv('r2_access_key', $settings) ?>"></div>
            <div class="form-group"><label>Secret Access Key</label><input type="text" name="r2_secret_key" value="<?= sv('r2_secret_key', $settings) ?>"></div>
          </div>
        </details>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:20px">
    <div class="card-header">Stripeとシステム側の設定手順</div>
    <div class="card-body">
      <div class="responsive-grid">
        <div>
          <h3 class="settings-subtitle">Stripe側で行うこと</h3>
          <ol class="settings-steps">
            <li>Stripeの商品カタログで月額・年額のサブスク商品を作成します。</li>
            <li>月額は「継続・毎月」、年額は「継続・毎年」のPriceを作成します。</li>
            <li>作成した価格のID（<code>price_...</code>）をこの画面へ入力します。</li>
            <li>回数券はこのシステム側の回数・金額からCheckoutを作成するため、Stripe側でPrice IDを作る必要はありません。</li>
            <li>Webhook URLに <code>https://school.sengoku-ai.com/stripe/webhook</code> を設定します。</li>
          </ol>
        </div>
        <div>
          <h3 class="settings-subtitle">画像品質を上げる設定</h3>
          <ol class="settings-steps">
            <li>人物・子ども向け安全エンジンをOpenAIにします。</li>
            <li>OpenAI品質を「高品質」にします。</li>
            <li>禁止ワードに「怖い顔」「崩れた顔」「余分な指」「不自然な手」などを入れます。</li>
            <li>Stability残高不足時の自動切替を有効にし、フォールバック先をOpenAIにします。</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div style="margin-top:20px;display:flex;justify-content:flex-end">
    <button type="submit" class="btn btn-primary">設定を保存</button>
  </div>
</form>

<style>
.setting-help {
  font-size: 12px;
  color: var(--muted);
  margin: 6px 0 0;
  line-height: 1.7;
}
.settings-two {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.settings-plan-list {
  display: grid;
  gap: 8px;
}
.checkbox-row label {
  display: flex;
  align-items: center;
  gap: 8px;
}
.details-body {
  margin-top: 12px;
}
.settings-subtitle {
  font-size: 15px;
  margin: 0 0 10px;
}
.settings-steps {
  margin: 0;
  padding-left: 20px;
  color: var(--muted);
  line-height: 1.9;
}
@media (max-width: 720px) {
  .settings-two {
    grid-template-columns: 1fr;
  }
}
</style>

<script>
function testApi(type) {
  const resultEl = document.getElementById('test-result');
  resultEl.style.display = 'block';
  resultEl.className = 'alert';
  resultEl.textContent = type.toUpperCase() + ' の接続を確認しています...';

  const params = { type };
  const map = {
    line: 'line_channel_access_token',
    claude: 'claude_api_key',
    openai: 'openai_api_key',
    stability: 'stability_api_key',
    grok: 'grok_api_key',
    stripe: 'stripe_secret_key'
  };
  if (map[type] && document.getElementById(map[type])) {
    params.key = document.getElementById(map[type]).value;
    params.token = document.getElementById(map[type]).value;
  }

  fetch('/admin/settings/test', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(params)
  })
  .then(response => response.json())
  .then(data => {
    resultEl.className = 'alert ' + (data.ok ? 'alert-success' : 'alert-error');
    resultEl.textContent = data.message || (data.ok ? '接続できました。' : '接続に失敗しました。');
  })
  .catch(() => {
    resultEl.className = 'alert alert-error';
    resultEl.textContent = '通信エラーが発生しました。ログイン状態またはサーバー設定を確認してください。';
  });
}
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
