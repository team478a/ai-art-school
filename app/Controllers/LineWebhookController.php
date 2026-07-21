<?php
// app/Controllers/LineWebhookController.php

require_once BASE_PATH . '/config/app.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/Logger.php';
require_once BASE_PATH . '/app/Services/SurveyDefinition.php';
require_once BASE_PATH . '/app/Services/UserSessionService.php';
require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';
require_once BASE_PATH . '/app/Services/GenerationTestAccessService.php';
require_once BASE_PATH . '/app/Services/CommonIntegrationService.php';

class LineWebhookController {
    private LineService $line;
    private PDO $pdo;
    private UserSessionService $session;
    private ClassScheduleService $classSvc;
    private TenantScopeService $tenant;
    private GenerationTestAccessService $generationTestAccess;

    public function __construct() {
        $this->line    = new LineService();
        $this->pdo     = get_pdo();
        $this->session  = new UserSessionService();
        $this->classSvc = new ClassScheduleService();
        $this->tenant   = new TenantScopeService($this->pdo);
        $this->generationTestAccess = new GenerationTestAccessService($this->pdo);
    }

    public function handle(): void {
        $body      = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

        if (!$this->line->verifySignature($body, $signature)) {
            Logger::warning('line', "署名検証失敗");
            http_response_code(403);
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }

        $payload = json_decode($body, true);
        if (!$payload) { http_response_code(400); return; }

        // 期限切れセッションを定期クリーンアップ（10%の確率）
        if (rand(1, 10) === 1) $this->session->cleanup();

        foreach ($payload['events'] ?? [] as $event) {
            try {
                $this->handleEvent($event);
            } catch (\Throwable $e) {
                Logger::error('line', "イベント処理エラー: " . $e->getMessage());
            }
        }

        http_response_code(200);
        echo json_encode(['status' => 'ok']);

        // LINEへの応答を即座に返し、裏で溜まったジョブを処理する
        // （cronが止まっていても受講生の操作で処理が進む保険）
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $this->processQueuedJobs();
    }

    // 溜まっているジョブを最大2件処理（Webカウント時の保険処理）
    private function processQueuedJobs(): void {
        try {
            $pendingStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM job_queue
                 WHERE status = 'pending'
                   AND (available_at IS NULL OR available_at <= NOW())"
                . $this->tenant->andWhere('job_queue')
            );
            $pendingStmt->execute($this->tenant->params('job_queue'));
            $pending = (int)$pendingStmt->fetchColumn();
            if ($pending === 0) return;

            require_once BASE_PATH . '/app/Services/PromptService.php';
            require_once BASE_PATH . '/app/Services/ImageGenerationService.php';
            require_once BASE_PATH . '/app/Services/StorageService.php';
            require_once BASE_PATH . '/app/Workers/GenerateImagesWorker.php';

            $worker = new GenerateImagesWorker();
            // 最大2件だけ処理（Webhookを重くしすぎない）
            for ($i = 0; $i < 2; $i++) {
                $leftStmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM job_queue
                     WHERE status = 'pending'
                       AND (available_at IS NULL OR available_at <= NOW())"
                    . $this->tenant->andWhere('job_queue')
                );
                $leftStmt->execute($this->tenant->params('job_queue'));
                $left = (int)$leftStmt->fetchColumn();
                if ($left === 0) break;
                $worker->run();
            }
            // 死活監視の時刻も更新
            Settings::set('worker_last_run', date('Y-m-d H:i:s'));

            // リマインダー送信
            require_once BASE_PATH . '/app/Services/ReminderService.php';
            (new ReminderService())->dispatchDue();
        } catch (\Throwable $e) {
            Logger::error('worker', "Webhook処理エラー: " . $e->getMessage());
        }
    }

    private function handleEvent(array $event): void {
        $type       = $event['type'] ?? '';
        $lineUserId = $event['source']['userId'] ?? '';

        if ($type === 'follow') {
            $this->handleFollow($lineUserId, $event);
            return;
        }

        // アンフォロー（ブロック・友だち削除）時はユーザーを非アクティブに
        if ($type === 'unfollow') {
            if ($lineUserId) {
                [$tenantWhere, $tenantParams] = $this->tenantWhereFor('users');
                $this->pdo->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE line_user_id = ? {$tenantWhere}")
                    ->execute(array_merge([$lineUserId], $tenantParams));
                Logger::info('line', "アンフォロー line={$lineUserId}");
            }
            return;
        }

        if ($type === 'message' && ($event['message']['type'] ?? '') === 'image') {
            $this->handleImageMessage($lineUserId, $event);
            return;
        }

        if ($type === 'message' && ($event['message']['type'] ?? '') === 'text') {
            $this->handleTextMessage($lineUserId, $event);
        }
    }

    private function assignTenantToRow(string $table, int $id): void {
        if (
            $id <= 0
            || !$this->tenant->tenantId()
            || !$this->tenant->hasTenantColumn($table)
            || !$this->tenant->isDefaultTenant()
        ) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE `{$table}` SET tenant_id = ? WHERE id = ? AND tenant_id IS NULL");
            $stmt->execute([(int)$this->tenant->tenantId(), $id]);
        } catch (Throwable $e) {
            Logger::warning('tenant', "tenant_id assignment failed table={$table} id={$id}: " . $e->getMessage());
        }
    }

    private function tenantWhereFor(string $table, string $alias = ''): array {
        return [
            $this->tenant->andWhere($table, $alias),
            $this->tenant->params($table),
        ];
    }

    private function insertTenantRecord(string $table, array $data, bool $withUpdatedAt = true): int {
        [$columns, $values] = $this->tenant->assignInsert(
            $table,
            array_keys($data),
            array_values($data)
        );
        $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $timestampColumns = $withUpdatedAt ? ', created_at, updated_at' : ', created_at';
        $timestampValues = $withUpdatedAt ? ', NOW(), NOW()' : ', NOW()';
        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedColumns) . $timestampColumns
            . ') VALUES (' . $placeholders . $timestampValues . ')';
        $this->pdo->prepare($sql)->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    private function queueGeneration(int $requestId): void {
        $this->insertTenantRecord('job_queue', [
            'request_id' => $requestId,
            'job_type' => 'generate_images',
            'status' => 'pending',
        ]);
    }

    private function attendanceTenantWhere(string $attendanceAlias = 'a', string $scheduleAlias = 's'): array {
        $where = '';
        $params = [];
        foreach ([
            ['class_attendances', $attendanceAlias],
            ['class_schedules', $scheduleAlias],
        ] as [$table, $alias]) {
            $part = $this->tenant->andWhere($table, $alias);
            if ($part === '') {
                continue;
            }
            $where .= $part;
            $params = array_merge($params, $this->tenant->params($table));
        }
        return [$where, $params];
    }

    // フォロー時
    private function handleFollow(string $lineUserId, array $event): void {
        $this->upsertUser($lineUserId);
        // 再フォロー時は active に戻す
        [$tenantWhere, $tenantParams] = $this->tenantWhereFor('users');
        $this->pdo->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE line_user_id = ? {$tenantWhere}")
            ->execute(array_merge([$lineUserId], $tenantParams));
        $replyToken = $event['replyToken'] ?? '';
        if ($replyToken) {
            // カスタムあいさつメッセージ（管理画面で編集可能）
            $greeting = Settings::get('greeting_message', '');
            if (!$greeting) {
                $greeting = "AIアート教室へようこそ！\n\n「参加予約」から予約カレンダーを開いて、参加したい教室を選んでください。\n当日は「参加」で出席確認できます🎨\n\nメニューから操作してください。";
            }
            // 規約URLがあれば案内を添える
            $terms = Settings::get('terms_url', '');
            if ($terms) {
                $greeting .= "\n\nご利用にあたっては利用規約をご確認ください。「規約」と送るとご案内します。";
            }
            $this->line->replyText($replyToken, $greeting);
        }
    }

    // テキスト受信 — アンケートの状態によって分岐
    private function handleTextMessage(string $lineUserId, array $event): void {
        $text       = trim($event['message']['text'] ?? '');
        $replyToken = $event['replyToken'] ?? '';
        if (!$text) return;

        $user    = $this->upsertUser($lineUserId);
        $session = $this->session->get($lineUserId);
        $step    = $session['step'] ?? SurveyDefinition::STEP_IDLE;

        if ($step === 'photo_style') {
            $this->handlePhotoStyleAnswer($lineUserId, $user, $session, $text, $replyToken);
            return;
        }

        // 履歴コマンド
        if (in_array(mb_strtolower($text), ['履歴', 'history', 'きろく', '記録'])) {
            $this->handleHistory($lineUserId, $replyToken);
            return;
        }

        // マイページコマンド
        if (in_array(mb_strtolower($text), ['マイページ', 'mypage', '予約確認', '予約状況', '会員情報', 'ステータス'])) {
            $this->handleMyPage($lineUserId, $user, $replyToken);
            return;
        }

        // 開催日コマンド
        if (in_array($text, ['開催日', 'スケジュール', '日程'])) {
            $this->handleScheduleInfo($replyToken);
            return;
        }

        // 使い方コマンド
        if (in_array($text, ['使い方', 'つかいかた', 'ヘルプ', 'help'])) {
            $this->handleHelp($replyToken);
            return;
        }

        // お問合せコマンド
        if (in_array($text, ['お問合せ', 'お問い合わせ', '問い合わせ', 'contact'])) {
            $contactMsg = Settings::get('contact_message', '');
            if (!$contactMsg) {
                $contactMsg = "お問い合わせは教室スタッフまでお願いします。";
            }
            $this->line->replyText($replyToken, $contactMsg);
            return;
        }

        // キャンセルコマンド
        if (in_array($text, ['キャンセル', 'cancel', 'やめる', 'やりなおす'])) {
            $this->session->clear($lineUserId);
            $this->line->replyText($replyToken, "キャンセルしました。\nまた「生成する」で始められます。");
            return;
        }

        switch ($step) {
            case SurveyDefinition::STEP_IDLE:
                $this->handleIdle($lineUserId, $user, $text, $replyToken);
                break;
            case SurveyDefinition::STEP_MODE:
                $this->handleModeSelect($lineUserId, $session, $text, $replyToken);
                break;
            case SurveyDefinition::STEP_FREE:
                $this->handleFreeInput($lineUserId, $user, $session, $text, $replyToken);
                break;
            case SurveyDefinition::STEP_STYLE:
                $this->handleStyleAnswer($lineUserId, $session, $text, $replyToken);
                break;
            case SurveyDefinition::STEP_MOOD:
                $this->handleMoodAnswer($lineUserId, $session, $text, $replyToken);
                break;
            case SurveyDefinition::STEP_KEYWORD:
                $this->handleKeywordInput($lineUserId, $user, $session, $text, $replyToken);
                break;
        }
    }

    // idle状態 — 「参加する」チェックイン or「生成する」でアンケート開始
    private function handleIdle(string $lineUserId, array $user, string $text, string $replyToken): void {
        $classMode = Settings::get('class_mode_enabled', '1') === '1';

        // 「参加予約」＝予約カレンダーを開く
        $isReserve = in_array(mb_strtolower($text), ['参加予約', '予約', 'よやく']);
        if ($isReserve) {
            $this->handleReservationCalendar($replyToken);
            return;
        }

        // 「参加」＝当日チェックイン（実際に来た記録）
        $isAttend = in_array(mb_strtolower($text), ['参加', '参加する', 'チェックイン', 'join', '出席']);
        if ($isAttend) {
            $this->handleAttend($lineUserId, $user, $replyToken);
            return;
        }

        // 「キャンセル」＝予約取消
        if (in_array(mb_strtolower($text), ['キャンセル', 'cancel', '取消', '取り消し', 'よやくとりけし'])) {
            $this->handleCancel($lineUserId, $user, $replyToken);
            return;
        }

        // 「もう一回」＝直近の依頼を再生成
        if (in_array($text, ['もう一回', 'もういちど', 'もう一度', '再生成', 'リトライ', 'やり直し'])) {
            $this->handleRegenerate($lineUserId, $user, $replyToken);
            return;
        }

        // 「規約」「プライバシー」案内
        if (in_array($text, ['規約', '利用規約', 'プライバシー', 'プライバシーポリシー', '個人情報'])) {
            $terms = Settings::get('terms_url', '');
            $privacy = Settings::get('privacy_url', '');
            if (!$terms && !$privacy) {
                $this->line->replyText($replyToken, "現在準備中です。詳しくは教室スタッフにお問い合わせください。");
            } else {
                $msg = "📄 各種ご案内\n\n";
                if ($terms)   $msg .= "【利用規約】\n{$terms}\n\n";
                if ($privacy) $msg .= "【プライバシーポリシー】\n{$privacy}\n";
                $this->line->replyText($replyToken, trim($msg));
            }
            return;
        }

        // 「チケット購入」「回数券」
        if (in_array($text, ['チケット購入', 'チケット', '回数券', 'チケットを買う'])) {
            $this->handleTicketPurchase($lineUserId, $user, $replyToken);
            return;
        }

        // チケットプラン選択（チケット購入:0 形式）
        if (preg_match('/^チケット購入:(\d+)$/u', $text, $tm)) {
            $this->handleTicketSelect($lineUserId, $user, (int)$tm[1], $replyToken);
            return;
        }

        // 「サブスク」「会員登録」
        if (in_array($text, ['サブスク', '会員登録', 'サブスク登録', '月額会員'])) {
            $this->handleSubscribe($lineUserId, $user, $replyToken);
            return;
        }

        // 「生成する」トリガー
        $triggers = ['生成する', '生成', '作る', '作って', 'start', '始める', '画像'];
        $isStart  = false;
        foreach ($triggers as $t) {
            if (mb_strpos($text, $t) !== false) { $isStart = true; break; }
        }

        if (!$isStart) {
            // クイックリプライボタンの内容をクラスモードに応じて変える
            $buttons = [['type'=>'action','action'=>['type'=>'message','label'=>'✨ 生成する','text'=>'生成する']]];
            if ($classMode) {
                array_unshift($buttons, ['type'=>'action','action'=>['type'=>'message','label'=>'🎓 参加予約','text'=>'参加予約']]);
                array_splice($buttons, 1, 0, [['type'=>'action','action'=>['type'=>'message','label'=>'✅ 参加','text'=>'参加']]]);
            }
            $buttons[] = ['type'=>'action','action'=>['type'=>'message','label'=>'👤 マイページ','text'=>'マイページ']];
            $buttons[] = ['type'=>'action','action'=>['type'=>'message','label'=>'❓ 使い方','text'=>'使い方']];
            $buttons[] = ['type'=>'action','action'=>['type'=>'message','label'=>'💬 お問合せ','text'=>'お問合せ']];
            $this->line->replyWithQuickReply($replyToken,
                "メニューから操作を選んでください🎨\n「生成する」で画像生成、「使い方」で操作説明が見られます。", $buttons);
            return;
        }

        // クラスモードのチェック
        if ($classMode && $this->shouldRequireClassAttendanceGate($lineUserId)) {
            if (!$this->classSvc->hasTodayClass()) {
                $next = $this->classSvc->getNextSchedule();
                $nextStr = $next ? date('m月d日（D）', strtotime($next['class_date'])) : 'お知らせをお待ちください';
                $this->line->replyText($replyToken,
                    "現在、教室の開催日ではありません。
次回：{$nextStr}");
                return;
            }
            if (!$this->classSvc->isApprovedToday($lineUserId)) {
                $canApply = $this->classSvc->isCheckinOpen();
                if ($canApply) {
                    $this->line->replyWithQuickReply($replyToken,
                        "画像生成を使うには、予約済みの教室で当日の参加確認が必要です。",
                        [
                            ['type'=>'action','action'=>['type'=>'message','label'=>'✅ 参加','text'=>'参加']],
                            ['type'=>'action','action'=>['type'=>'message','label'=>'🎓 参加予約','text'=>'参加予約']],
                        ]
                    );
                } else {
                    $this->line->replyText($replyToken,
                        "参加確認の受付時間外です。
予約は「参加予約」からカレンダーを開いて行ってください。");
                }
                return;
            }
            if (!$this->hasCheckedInToday($lineUserId)) {
                $this->line->replyWithQuickReply($replyToken,
                    "画像生成は、当日の参加確認後に利用できます。\n教室当日に「参加」ボタンで出席確認をしてから、「生成する」を押してください。",
                    [
                        ['type'=>'action','action'=>['type'=>'message','label'=>'参加','text'=>'参加']],
                        ['type'=>'action','action'=>['type'=>'message','label'=>'参加予約','text'=>'参加予約']],
                    ]
                );
                return;
            }
        }

        if (!$this->ensureCanGenerateNow($lineUserId, $replyToken)) return;

        // 上限チェック（スケジュールのmax_requestsを使用）
        if (!$this->checkDailyLimit($lineUserId, $replyToken)) return;

        // 形式選択（アンケート / 自由記述）へ
        $this->session->start($lineUserId);
        $this->session->advance($lineUserId, SurveyDefinition::STEP_MODE, []);
        $this->askMode($replyToken);
    }

    // 生成方式の選択
    private function askMode(string $replyToken): void {
        $this->line->replyWithQuickReply($replyToken,
            "画像生成を始めます🎨\nどちらの方法で作りますか？",
            [
                ['type'=>'action','action'=>['type'=>'message','label'=>'📋 アンケートで選ぶ','text'=>'アンケート']],
                ['type'=>'action','action'=>['type'=>'message','label'=>'✍️ 自由に書く','text'=>'自由記述']],
            ]
        );
    }

    // LINE用の会場/Zoom案内文
    private function buildAccessInfoForLine(array $schedule): string {
        $fmt = $schedule['event_format'] ?? 'realtime';
        $info = '';
        if (($fmt === 'zoom' || $fmt === 'hybrid') && !empty($schedule['zoom_url'])) {
            $info .= "🎥 Zoom参加URL\n{$schedule['zoom_url']}\n";
        }
        if (($fmt === 'realtime' || $fmt === 'hybrid') && !empty($schedule['location'])) {
            $info .= "📍 会場：{$schedule['location']}\n";
        }
        if ($info !== '') $info .= "\n";
        return $info;
    }

    // 予約・支払い・会員状態をユーザーに送信
    private function handleMyPage(string $lineUserId, array $user, string $replyToken): void {
        [$tenantWhere, $tenantParams] = $this->attendanceTenantWhere('a', 's');
        $stmt = $this->pdo->prepare("
            SELECT a.*, s.title, s.class_date, s.start_time, s.end_time, s.event_format, s.location, s.zoom_url
            FROM class_attendances a
            INNER JOIN class_schedules s ON s.id = a.schedule_id
            WHERE a.user_id = ?
              AND s.class_date >= CURDATE()
              AND a.status IN ('pending','approved')
              AND s.status IN ('scheduled','active')
              {$tenantWhere}
            ORDER BY s.class_date ASC, s.start_time ASC
            LIMIT 5
        ");
        $stmt->execute(array_merge([(int)$user['id']], $tenantParams));
        $reservations = $stmt->fetchAll();

        $memberType = $user['member_type'] ?? 'none';
        $subscriptionUntil = $user['subscription_until'] ?? null;
        $isSubscriber = $memberType === 'subscriber' && (!$subscriptionUntil || strtotime($subscriptionUntil) >= time());
        $ticketBalance = (int)($user['ticket_balance'] ?? 0);
        $ticketExpires = $user['ticket_expires_at'] ?? null;

        $msg = "👤 マイページ\n";
        $msg .= str_repeat('─', 15) . "\n\n";

        if ($isSubscriber) {
            $msg .= "会員状態：サブスク会員";
            if ($subscriptionUntil) {
                $msg .= "（" . date('Y/m/d', strtotime($subscriptionUntil)) . "まで）";
            }
            $msg .= "\n";
        } elseif ($memberType === 'subscriber') {
            $msg .= "会員状態：サブスク期限切れ\n";
        } else {
            $msg .= "会員状態：通常会員\n";
        }

        $msg .= "チケット残数：{$ticketBalance}枚";
        if ($ticketExpires) {
            $msg .= "（期限 " . date('Y/m/d', strtotime($ticketExpires)) . "）";
        }
        $msg .= "\n\n";

        if ($reservations) {
            $msg .= "📅 今後の予約\n";
            foreach ($reservations as $r) {
                $date = date('n/j', strtotime($r['class_date']));
                $start = substr($r['start_time'], 0, 5);
                $status = $this->attendanceStatusLabel($r['status'] ?? '');
                $payment = $this->paymentStatusLabel($r['payment_status'] ?? '', (int)($r['payment_amount'] ?? 0));
                $msg .= "・{$date} {$start} {$r['title']}\n";
                $msg .= "  予約：{$status} / 支払い：{$payment}\n";
            }
        } else {
            $msg .= "📅 今後の予約：なし\n";
        }

        $msg .= "\n予約は「参加予約」、当日の出席確認は「参加」を押してください。";

        $this->line->replyWithQuickReply($replyToken, $msg, [
            ['type'=>'action','action'=>['type'=>'message','label'=>'🎓 参加予約','text'=>'参加予約']],
            ['type'=>'action','action'=>['type'=>'message','label'=>'✅ 参加','text'=>'参加']],
            ['type'=>'action','action'=>['type'=>'message','label'=>'🎫 チケット','text'=>'チケット購入']],
            ['type'=>'action','action'=>['type'=>'message','label'=>'🌟 サブスク','text'=>'サブスク']],
        ]);
    }

    private function attendanceStatusLabel(string $status): string {
        switch ($status) {
            case 'approved': return '承認済み';
            case 'pending': return '予約受付中';
            case 'rejected': return '却下';
            case 'cancelled': return 'キャンセル';
        }
        return $status ?: '不明';
    }

    private function paymentStatusLabel(string $status, int $amount): string {
        switch ($status) {
            case 'paid': return '支払い済み';
            case 'unpaid': return $amount > 0 ? "未払い {$amount}円" : '未払い';
            case 'free': return '無料';
            case 'subscription': return 'サブスク';
            case 'ticket': return 'チケット利用';
        }
        return $amount > 0 ? "{$amount}円" : '不要';
    }

    private function handleHistory(string $lineUserId, string $replyToken): void {
        // 参加履歴（直近5件）
        [$attendanceTenantWhere, $attendanceTenantParams] = $this->attendanceTenantWhere('a', 's');
        $stmtA = $this->pdo->prepare("
            SELECT a.*, s.title, s.class_date
            FROM class_attendances a
            LEFT JOIN class_schedules s ON s.id = a.schedule_id
            WHERE a.line_user_id = ? AND a.status = 'approved'
              {$attendanceTenantWhere}
            ORDER BY s.class_date DESC LIMIT 5
        ");
        $stmtA->execute(array_merge([$lineUserId], $attendanceTenantParams));
        $attendances = $stmtA->fetchAll();

        // 生成履歴（直近5件）
        [$requestTenantWhere, $requestTenantParams] = $this->tenantWhereFor('image_requests');
        $stmtR = $this->pdo->prepare("
            SELECT * FROM image_requests
            WHERE line_user_id = ? AND status = 'completed'
              {$requestTenantWhere}
            ORDER BY created_at DESC LIMIT 5
        ");
        $stmtR->execute(array_merge([$lineUserId], $requestTenantParams));
        $requests = $stmtR->fetchAll();

        // メッセージ作成
        $msg = "📋 あなたの履歴
";
        $msg .= str_repeat('─', 15) . "
";

        if ($attendances) {
            $msg .= "
🎓 参加履歴（直近5回）
";
            foreach ($attendances as $a) {
                $date = $a['class_date'] ? date('m/d', strtotime($a['class_date'])) : '—';
                $msg .= "・{$date} {$a['title']}
";
            }
        } else {
            $msg .= "
🎓 参加履歴：なし
";
        }

        if ($requests) {
            $msg .= "
🎨 画像生成履歴（直近5件）
";
            foreach ($requests as $r) {
                $date  = date('m/d', strtotime($r['created_at']));
                $input = mb_strimwidth($r['input_text'], 0, 15, '…');
                $style = $r['survey_style'] ?? '';
                $mood  = $r['survey_mood']  ?? '';
                $tag   = $style ? "[{$style}]" : '';
                $msg .= "・{$date} {$tag}{$input}
";
            }
        } else {
            $msg .= "
🎨 生成履歴：なし
";
        }

        $msg .= "
「生成する」で画像生成を始められます。";

        $this->line->replyText($replyToken, $msg);
    }

    // 開催日案内
    private function handleScheduleInfo(string $replyToken): void {
        $next = $this->classSvc->getNextSchedule();
        if (!$next) {
            $msg = Settings::get('next_class_message', '次回の教室開催日をお待ちください。');
            $url = $this->reservationCalendarUrl();
            if ($url) {
                $msg .= "\n\n予約カレンダー：{$url}";
            }
        } else {
            $date  = date('n月j日（D）', strtotime($next['class_date']));
            $start = substr($next['start_time'], 0, 5);
            $end   = substr($next['end_time'], 0, 5);
            $open  = substr($next['checkin_open'], 0, 5);
            $close = substr($next['checkin_close'], 0, 5);
            $msg  = "📅 次回の教室\n";
            $msg .= str_repeat('─', 12) . "\n";
            $msg .= "{$next['title']}\n";
            $msg .= "日時：{$date} {$start}〜{$end}\n";
            $msg .= "参加受付：{$open}〜{$close}\n";
            if (!empty($next['organizer'])) {
                $msg .= "主催：{$next['organizer']}\n";
            }
            // 開催形式・場所
            $fmt = $next['event_format'] ?? 'realtime';
            if ($fmt === 'zoom') {
                $msg .= "形式：オンライン（Zoom）\n";
                $msg .= "※ ZoomのURLは参加承認後にご案内します\n";
            } elseif ($fmt === 'hybrid') {
                $msg .= "形式：会場＋オンライン\n";
                if (!empty($next['location'])) $msg .= "会場：{$next['location']}\n";
                $msg .= "※ ZoomのURLは参加承認後にご案内します\n";
            } else {
                if (!empty($next['location'])) $msg .= "会場：{$next['location']}\n";
            }
            if (!empty($next['public_message'])) {
                $msg .= "\n" . $next['public_message'] . "\n";
            }
            $url = $this->reservationCalendarUrl();
            $msg .= "\n予約する場合は「参加予約」を押してください。";
            if ($url) {
                $msg .= "\n予約カレンダー：{$url}";
            }
        }
        $this->line->replyText($replyToken, $msg);
    }

    private function handleReservationCalendar(string $replyToken): void {
        $url = $this->reservationCalendarUrl();
        if (!$url) {
            $this->line->replyText($replyToken,
                "予約カレンダーURLを作成できませんでした。管理者にLIFF設定を確認してもらってください。");
            return;
        }

        $items = [[
            'type' => 'action',
            'action' => [
                'type' => 'uri',
                'label' => '予約カレンダーを開く',
                'uri' => $url,
            ],
        ]];
        if ($this->classSvc->hasTodayClass()) {
            $items[] = ['type'=>'action','action'=>['type'=>'message','label'=>'✅ 参加','text'=>'参加']];
        }

        $this->line->replyWithQuickReply($replyToken,
            "予約カレンダーから、参加したいAIアート教室を選んでください。\n\n{$url}\n\n当日は教室に着いたら「参加」を押してください。",
            $items
        );
    }

    private function reservationCalendarUrl(): string {
        $base = trim((string)Settings::get('public_base_url', ''));
        if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $base = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        } elseif ($base === '') {
            $base = Settings::get('app_url', '') ?: Settings::get('site_url', '') ?: Settings::get('base_url', '');
        }
        $base = rtrim($base, '/');
        if ($base === '') {
            return '';
        }
        $tenant = Settings::currentTenant();
        $key = trim((string)($tenant['tenant_key'] ?? ''));
        return $base . '/liff/calendar' . ($key !== '' ? '?tenant=' . rawurlencode($key) : '');
    }

    // 使い方案内
    private function handleHelp(string $replyToken): void {
        $msg  = "❓ 使い方\n";
        $msg .= str_repeat('─', 12) . "\n\n";
        $msg .= "【1】「参加予約」から予約カレンダーを開く\n";
        $msg .= "→ 参加したい教室を選んで予約します\n";
        $msg .= "→ 有料の場合は支払いへ進みます\n\n";
        $msg .= "【2】当日に「参加」を押す\n";
        $msg .= "→ 出席確認が完了します\n\n";
        $msg .= "【3】「生成する」を押す\n";
        $msg .= "→ 画風・雰囲気を選んで\n  キーワードを送ります\n\n";
        $msg .= "【4】画像が届く\n";
        $msg .= "→ 設定された上限まで画像をお届け🎨\n\n";
        $msg .= "「マイページ」で予約・支払い・チケット残数を確認できます。\n";
        $msg .= "「履歴」で過去の作品を確認できます。";
        $this->line->replyText($replyToken, $msg);
    }

    // チェックイン処理
    // 直近の依頼を再生成
    private function handleRegenerate(string $lineUserId, array $user, string $replyToken): void {
        [$tenantWhere, $tenantParams] = $this->tenantWhereFor('image_requests');
        $stmt = $this->pdo->prepare("
            SELECT * FROM image_requests
            WHERE line_user_id = ?
              {$tenantWhere}
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(array_merge([$lineUserId], $tenantParams));
        $last = $stmt->fetch();

        if (!$last) {
            $this->line->replyText($replyToken,
                "再生成できる前回の作品が見つかりませんでした。\n「生成する」から新しく作成してください🎨");
            return;
        }

        if (!$this->checkDailyLimit($lineUserId, $replyToken)) return;

        $requestId = $this->insertTenantRecord('image_requests', [
            'user_id' => (int)$user['id'],
            'line_user_id' => $lineUserId,
            'input_type' => (string)$last['input_type'],
            'input_text' => (string)$last['input_text'],
            'survey_style' => $last['survey_style'] ?? null,
            'survey_mood' => $last['survey_mood'] ?? null,
            'status' => 'received',
        ]);
        $this->queueGeneration($requestId);

        Logger::info('line', "再生成依頼受付 request_id={$requestId}（元={$last['id']}）", $requestId);

        $this->line->replyText($replyToken,
            "🔄 もう一度生成します！\n\n前回と同じ内容で、新しいパターンの画像を作成中です。\n完成までしばらくお待ちください（通常3〜5分）🎨");
    }

    // チケット購入メニュー
    private function handleTicketPurchase(string $lineUserId, array $user, string $replyToken): void {
        if ($this->replyShoppingPurchaseLinkIfActive($replyToken)) {
            return;
        }
        require_once BASE_PATH . '/app/Services/StripeService.php';
        $stripe = new StripeService();
        if (!$stripe->isConfigured()) {
            $this->line->replyText($replyToken, "現在チケットのオンライン購入は準備中です。教室スタッフにお問い合わせください。");
            return;
        }
        $plans = json_decode(Settings::get('ticket_plans', '[]'), true) ?: [];
        if (empty($plans)) {
            $this->line->replyText($replyToken, "現在販売中のチケットがありません。");
            return;
        }
        $bubbles = [];
        foreach ($plans as $i => $p) {
            $bubbles[] = ['type'=>'action','action'=>[
                'type'=>'message',
                'label'=> mb_substr("{$p['count']}回 {$p['price']}円", 0, 20),
                'text'=> "チケット購入:{$i}",
            ]];
            if (count($bubbles) >= 13) break;
        }
        $this->line->replyWithQuickReply($replyToken,
            "🎫 回数券をお選びください\n購入後すぐにご利用いただけます。", $bubbles);
    }

    // チケットプラン選択後の決済リンク発行
    private function handleTicketSelect(string $lineUserId, array $user, int $planIndex, string $replyToken): void {
        if ($this->replyShoppingPurchaseLinkIfActive($replyToken)) {
            return;
        }
        require_once BASE_PATH . '/app/Services/StripeService.php';
        $stripe = new StripeService();
        $plans = json_decode(Settings::get('ticket_plans', '[]'), true) ?: [];
        if (!isset($plans[$planIndex])) {
            $this->line->replyText($replyToken, "そのプランは見つかりませんでした。");
            return;
        }
        $plan = $plans[$planIndex];
        $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $checkout = $stripe->createCheckout(
            (int)$plan['price'],
            "回数券 {$plan['count']}回分",
            ['kind' => 'ticket', 'user_id' => $user['id'], 'ticket_count' => $plan['count']],
            $base . '/liff/paid?type=ticket' . $this->tenantUrlSuffix(),
            $base . '/liff/calendar' . $this->tenantQueryString()
        );
        if ($checkout) {
            $this->line->replyText($replyToken,
                "🎫 {$plan['count']}回券（{$plan['price']}円）\n\n下記リンクからお支払いください👇\n{$checkout['url']}\n\nお支払い完了後、自動でチケットが追加されます。");
        } else {
            $this->line->replyText($replyToken, "決済リンクの発行に失敗しました。時間をおいてお試しください。");
        }
    }

    // サブスク加入
    private function handleSubscribe(string $lineUserId, array $user, string $replyToken): void {
        if (($user['member_type'] ?? 'none') === 'subscriber') {
            $this->line->replyText($replyToken, "すでにサブスク会員です🌟\n教室に何度でも無料でご参加いただけます。");
            return;
        }
        if ($this->replyShoppingPurchaseLinkIfActive($replyToken)) {
            return;
        }
        require_once BASE_PATH . '/app/Services/StripeService.php';
        $stripe = new StripeService();
        $priceId = Settings::get('stripe_subscription_price_id', '');
        if (!$stripe->isConfigured() || !$priceId) {
            $this->line->replyText($replyToken, "現在サブスクのオンライン登録は準備中です。教室スタッフにお問い合わせください。");
            return;
        }
        $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $checkout = $stripe->createSubscriptionCheckout(
            $priceId,
            ['kind' => 'subscription', 'user_id' => $user['id']],
            $base . '/liff/paid?type=subscription' . $this->tenantUrlSuffix(),
            $base . '/liff/calendar' . $this->tenantQueryString()
        );
        if ($checkout) {
            $price = Settings::get('subscription_price_label', '');
            $priceNote = $price ? "（{$price}）" : '';
            $this->line->replyText($replyToken,
                "🌟 月額サブスク会員{$priceNote}\n\n会員になると教室に何度でも無料でご参加いただけます。\n\n下記リンクからご登録ください👇\n{$checkout['url']}");
        } else {
            $this->line->replyText($replyToken, "登録リンクの発行に失敗しました。時間をおいてお試しください。");
        }
    }

    // 予約キャンセル
    private function handleCancel(string $lineUserId, array $user, string $replyToken): void {
        // 今後の予約を探す（今日以降で未参加のもの）
        $cancelled = $this->classSvc->cancelUpcomingReservation((int)$user['id']);
        if ($cancelled) {
            if (($cancelled['cancel_result'] ?? '') === 'refund_failed') {
                $date = date('n月j日', strtotime($cancelled['class_date']));
                $this->line->replyText($replyToken,
                    "{$date}「{$cancelled['title']}」は支払い済みの予約です。\n自動返金に失敗したため、キャンセルは完了していません。\nお手数ですが教室スタッフにお問い合わせください。");
                Logger::warning('class', "予約キャンセル返金失敗 line={$lineUserId} attendance={$cancelled['attendance_id']}");
                return;
            }

            $date = date('n月j日', strtotime($cancelled['class_date']));
            $note = '';
            if (($cancelled['refund_result'] ?? '') === 'refunded') {
                $note = "\n参加費は返金処理を行いました。";
            } elseif (!empty($cancelled['ticket_returned'])) {
                $note = "\n使用したチケット1枚を返却しました。";
            }
            $this->line->replyText($replyToken,
                "{$date}「{$cancelled['title']}」の予約をキャンセルしました。{$note}\nまたのご参加をお待ちしています。");
            Logger::info('class', "予約キャンセル line={$lineUserId} schedule={$cancelled['schedule_id']} attendance={$cancelled['attendance_id']}");
        } else {
            $this->line->replyText($replyToken,
                "キャンセル可能な予約が見つかりませんでした。");
        }
    }

    // 当日参加チェックイン（実際に来た記録）
    private function handleAttend(string $lineUserId, array $user, string $replyToken): void {
        $schedule = $this->classSvc->getTodaySchedule();

        if (!$schedule) {
            $this->line->replyText($replyToken, "本日は教室の開催がありません。");
            return;
        }

        // 受付時間内のみ
        if (!$this->classSvc->isCheckinOpen($schedule)) {
            $open  = substr($schedule['checkin_open'], 0, 5);
            $close = substr($schedule['checkin_close'], 0, 5);
            $this->line->replyText($replyToken,
                "当日参加の受付時間は {$open}〜{$close} です。\nお時間になりましたら「参加」を送ってください。");
            return;
        }

        require_once BASE_PATH . '/app/Services/BillingService.php';
        $billing = new BillingService();
        $att = $this->classSvc->getAttendance((int)$schedule['id'], $lineUserId);
        $judge = $billing->judge($user, $schedule);

        // 有料で未払いの場合は、出席記録を付ける前に支払いへ進める。
        if ($judge['type'] === 'paid' && $judge['amount'] > 0 && (!$att || ($att['payment_status'] ?? '') !== 'paid')) {
            $payment = $this->createAttendanceCheckout($schedule, $user, $lineUserId, $judge, $att);
            if ($payment['ok']) {
                $this->line->replyText($replyToken,
                    "参加費 {$judge['amount']}円のお支払いが必要です。\n下記URLからお支払いください。\n\n{$payment['url']}\n\nお支払い完了後、参加が確定します。");
            } else {
                $this->line->replyText($replyToken, $payment['message']);
            }
            return;
        }

        $result = $this->classSvc->checkInToday((int)$schedule['id'], (int)$user['id'], $lineUserId);
        $maxReq = $schedule['max_requests'] ?? 2;
        $access = $this->buildAccessInfoForLine($schedule);

        // 課金判定（無料・サブスク・チケット・支払い済みの記録）
        $feeNote = '';
        if ($result['result'] !== 'already') {
            // 最新のattendanceを取得して課金区分を適用
            $att = $this->classSvc->getAttendance((int)$schedule['id'], $lineUserId);
            if ($att) {
                if (!($judge['type'] === 'paid' && ($att['payment_status'] ?? '') === 'paid')) {
                    $billing->applyToAttendance((int)$att['id'], (int)$user['id'], $judge);
                }
                $feeNote = $this->buildFeeNote($judge, $user);
            }
        }

        switch ($result['result']) {
            case 'already':
                $this->line->replyText($replyToken,
                    "すでに参加チェックイン済みです✅\n「生成する」で画像生成を始められます🎨");
                break;
            case 'checked_in':
                $this->line->replyText($replyToken,
                    "✅ 参加を確認しました！ようこそ🎉\n\n" . $feeNote . $access .
                    "本日は {$maxReq}件まで画像生成できます。\n「生成する」と送って始めてください🎨");
                Logger::info('class', "当日チェックイン（予約者） line={$lineUserId} schedule={$schedule['id']}");
                break;
            case 'walk_in':
                $this->line->replyText($replyToken,
                    "✅ 当日参加を受け付けました！ようこそ🎉\n\n" . $feeNote . $access .
                    "本日は {$maxReq}件まで画像生成できます。\n「生成する」と送って始めてください🎨");
                Logger::info('class', "当日チェックイン（飛び込み） line={$lineUserId} schedule={$schedule['id']}");
                break;
        }
    }

    // 料金案内文を組み立て
    private function buildFeeNote(array $judge, array $user): string {
        switch ($judge['type']) {
            case 'free':
                return ($judge['message'] === '初回無料') ? "🎁 初回参加は無料です！\n\n" : '';
            case 'subscription':
                return "🌟 サブスク会員ですので参加無料です。\n\n";
            case 'ticket':
                $remain = max(0, (int)($user['ticket_balance'] ?? 0) - 1);
                return "🎫 チケットを1枚使用しました（残り{$remain}枚）。\n\n";
            case 'paid':
                return "💴 参加費：{$judge['amount']}円（支払い済み）\n\n";
        }
        return '';
    }

    private function createAttendanceCheckout(array $schedule, array $user, string $lineUserId, array $judge, ?array $attendance): array {
        require_once BASE_PATH . '/app/Services/StripeService.php';
        $stripe = new StripeService();
        if (!$stripe->isConfigured()) {
            return ['ok' => false, 'message' => '決済設定が未完了です。管理者にお問い合わせください。'];
        }

        $attendanceId = (int)($attendance['id'] ?? 0);
        if ($attendanceId <= 0) {
            [$columns, $values] = $this->tenant->assignInsert(
                'class_attendances',
                ['schedule_id', 'user_id', 'line_user_id', 'status', 'payment_status', 'payment_amount'],
                [(int)$schedule['id'], (int)$user['id'], $lineUserId, 'pending', 'unpaid', (int)$judge['amount']]
            );
            $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql = 'INSERT INTO class_attendances (' . implode(', ', $quotedColumns)
                . ', created_at, updated_at) VALUES (' . $placeholders . ', NOW(), NOW())';
            $this->pdo->prepare($sql)->execute($values);
            $attendanceId = (int)$this->pdo->lastInsertId();
        } else {
            $this->pdo->prepare("
                UPDATE class_attendances
                SET payment_status = 'unpaid', payment_amount = ?, updated_at = NOW()
                WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
            ")->execute(array_merge(
                [(int)$judge['amount'], $attendanceId],
                $this->tenant->params('class_attendances')
            ));
        }

        $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $tenantSuffix = $this->tenantUrlSuffix();
        $checkout = $stripe->createCheckout(
            (int)$judge['amount'],
            $schedule['title'] . ' 参加費',
            ['attendance_id' => $attendanceId, 'schedule_id' => (int)$schedule['id'], 'user_id' => (int)$user['id']],
            $base . '/liff/paid?attendance=' . $attendanceId . $tenantSuffix,
            $base . '/liff/calendar' . ($tenantSuffix !== '' ? '?' . ltrim($tenantSuffix, '&') : '')
        );

        if (!$checkout) {
            return ['ok' => false, 'message' => '決済ページの作成に失敗しました。時間をおいて再度お試しください。'];
        }

        $this->pdo->prepare(
            "UPDATE class_attendances SET stripe_session_id = ? WHERE id = ?"
            . $this->tenant->andWhere('class_attendances')
        )->execute(array_merge(
            [$checkout['id'], $attendanceId],
            $this->tenant->params('class_attendances')
        ));
        return ['ok' => true, 'url' => $checkout['url'], 'attendance_id' => $attendanceId];
    }

    private function tenantUrlSuffix(): string {
        $tenant = class_exists('Settings') ? Settings::currentTenant() : null;
        $tenantKey = trim((string)($tenant['tenant_key'] ?? ''));
        if ($tenantKey === '' || !empty($tenant['is_default'])) {
            return '';
        }
        return '&tenant=' . rawurlencode($tenantKey);
    }

    private function replyShoppingPurchaseLinkIfActive(string $replyToken): bool {
        require_once BASE_PATH . '/app/Services/ShoppingIntegrationService.php';
        $shopping = new ShoppingIntegrationService($this->pdo);
        if (!$shopping->isActive()) {
            return false;
        }
        if (!$shopping->isConfigured()) {
            $this->line->replyText($replyToken, "購入連携の準備中です。運営者へお問い合わせください。");
            return true;
        }
        $this->line->replyText(
            $replyToken,
            "購入・会員メニューはこちらです。\n" . $this->shoppingPurchaseUrl()
        );
        return true;
    }

    private function shoppingPurchaseUrl(): string {
        $base = rtrim((string)Settings::get('public_base_url', ''), '/');
        if ($base === '') {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $base = ($https ? 'https://' : 'http://') . (string)($_SERVER['HTTP_HOST'] ?? '');
        }
        $tenant = Settings::currentTenant() ?: [];
        $key = trim((string)($tenant['tenant_key'] ?? ''));
        return $base . '/liff/shop?from=line' . ($key !== '' ? '&tenant=' . rawurlencode($key) : '');
    }

    private function handleCheckin(string $lineUserId, array $user, string $replyToken): void {
        $schedule = $this->classSvc->getTodaySchedule();

        if (!$schedule) {
            $next = $this->classSvc->getNextSchedule();
            $nextStr = $next ? date('m月d日', strtotime($next['class_date'])) : 'お知らせをお待ちください';
            $this->line->replyText($replyToken, "本日の教室はありません。
次回：{$nextStr}");
            return;
        }

        if (!$this->classSvc->isCheckinOpen($schedule)) {
            $open  = substr($schedule['checkin_open'], 0, 5);
            $close = substr($schedule['checkin_close'], 0, 5);
            $this->line->replyText($replyToken,
                "参加申請の受付時間は {$open}〜{$close} です。
お時間になりましたら「参加予約」を送ってください。");
            return;
        }

        $result = $this->classSvc->applyAttendance((int)$schedule['id'], (int)$user['id'], $lineUserId);

        switch ($result['result']) {
            case 'applied':
                // 自動承認がオンなら即承認
                if (!empty($schedule['auto_approve'])) {
                    $this->classSvc->approveByScheduleUser((int)$schedule['id'], (int)$user['id']);
                    $maxReq = $schedule['max_requests'] ?? 2;
                    $access = $this->buildAccessInfoForLine($schedule);
                    $this->line->replyText($replyToken,
                        "✅ 参加が承認されました！

" . $access .
                        "本日は {$maxReq}件まで画像生成できます。
「生成する」と送って始めてください🎨");
                    Logger::info('class', "参加申請→自動承認 line={$lineUserId} schedule={$schedule['id']}");
                } else {
                    $this->line->replyText($replyToken,
                        "参加申請を受け付けました🎓
承認されたらLINEでお知らせします。
しばらくお待ちください。");
                    Logger::info('class', "参加申請 line={$lineUserId} schedule={$schedule['id']}");
                }
                break;
            case 'already':
                $statusMsg = $result['status'] === 'approved'
                    ? "すでに承認済みです。「生成する」で画像生成を始められます🎨"
                    : "すでに申請中です。承認をお待ちください。";
                $this->line->replyText($replyToken, $statusMsg);
                break;
            case 'full':
                $this->line->replyText($replyToken,
                    "本日の教室は定員に達しました。
次回のご参加をお待ちしています。");
                break;
            case 'blocked':
                $this->line->replyText($replyToken,
                    "現在、このアカウントでは予約できません。
管理者にお問い合わせください。");
                break;
        }
    }

    // Q1: 画風の回答処理
    private function handleStyleAnswer(string $lineUserId, array $session, string $text, string $replyToken): void {
        $styleKey = $this->matchChoice($text, SurveyDefinition::STYLES);

        if (!$styleKey) {
            $this->askStyle($replyToken, "下のボタンから選んでください👇");
            return;
        }

        // Q2へ進む
        $data = array_merge($session['survey_data'], ['style' => $styleKey]);
        $this->session->advance($lineUserId, SurveyDefinition::STEP_MOOD, $data);
        $this->askMood($replyToken, SurveyDefinition::styleLabel($styleKey));
    }

    // Q2: 雰囲気の回答処理
    private function handleMoodAnswer(string $lineUserId, array $session, string $text, string $replyToken): void {
        $moodKey = $this->matchChoice($text, SurveyDefinition::MOODS);

        if (!$moodKey) {
            $this->askMood($replyToken, null, "下のボタンから選んでください👇");
            return;
        }

        // Q3へ進む
        $data = array_merge($session['survey_data'], ['mood' => $moodKey]);
        $this->session->advance($lineUserId, SurveyDefinition::STEP_KEYWORD, $data);
        $this->askKeyword($replyToken, $session['survey_data']['style'] ?? '', $moodKey);
    }

    // Q3: キーワード入力 → 画像生成開始
    // 形式選択の回答
    private function handleModeSelect(string $lineUserId, array $session, string $text, string $replyToken): void {
        $t = mb_strtolower(trim($text));
        if (in_array($t, ['自由記述', '自由', 'じゆう', 'free', '✍️ 自由に書く', '自由に書く'])) {
            // 自由記述モードへ
            $this->session->advance($lineUserId, SurveyDefinition::STEP_FREE, []);
            $this->line->replyText($replyToken,
                "✍️ 自由記述モードです。\n\nどんな画像を作りたいか、自由に書いて送ってください。\n\n例：夕暮れの海辺に立つ着物姿の少女、桜が舞う幻想的な雰囲気\n\nなるべく具体的に書くと、イメージに近い画像ができます🎨");
            return;
        }
        if (in_array($t, ['アンケート', 'あんけーと', 'survey', '📋 アンケートで選ぶ', 'アンケートで選ぶ'])) {
            // アンケートモードへ（従来フロー）
            $this->session->advance($lineUserId, SurveyDefinition::STEP_STYLE, []);
            $this->askStyle($replyToken);
            return;
        }
        // どちらでもない → 再提示
        $this->askMode($replyToken);
    }

    // 自由記述の入力受付
    private function handleFreeInput(string $lineUserId, array $user, array $session, string $text, string $replyToken): void {
        if (!$this->ensureCanGenerateNow($lineUserId, $replyToken)) return;

        if (mb_strlen(trim($text)) < 3) {
            $this->line->replyText($replyToken,
                "もう少し詳しく書いてください🙏\n例：星空の下で踊る妖精、幻想的な雰囲気");
            return;
        }

        // NGワードチェック
        require_once BASE_PATH . '/app/Services/ContentFilter.php';
        if (!ContentFilter::isSafe($text)) {
            $this->line->replyText($replyToken,
                "申し訳ありません。その内容では画像を作成できません🙏\n別の表現でお試しください。");
            Logger::info('line', "NGワード検出（自由記述） line={$lineUserId}");
            return;
        }

        $this->session->clear($lineUserId);

        // 依頼保存（input_type = free）
        $requestId = $this->insertTenantRecord('image_requests', [
            'user_id' => (int)$user['id'],
            'line_user_id' => $lineUserId,
            'input_type' => 'free',
            'input_text' => $text,
            'status' => 'received',
        ]);
        $this->queueGeneration($requestId);

        Logger::info('line', "自由記述依頼受付 request_id={$requestId}", $requestId);

        $perPattern = max(1, min(4, (int)Settings::get('images_per_pattern', '4')));
        $total = min(max(1, Settings::maxImagesPerRequest()), $perPattern * 2);
        $countA = min($perPattern, (int)ceil($total / 2));
        $countB = max(0, $total - $countA);
        $breakdown = $countB > 0
            ? "Aパターン{$countA}枚・Bパターン{$countB}枚"
            : "Aパターン{$countA}枚";
        $this->line->replyText($replyToken,
            "ありがとうございます！画像生成を始めます🎨\n\n" .
            "【ご希望】{$text}\n\n" .
            "{$breakdown}、合計{$total}枚を作成中です。\n完成したらこのLINEにお送りします。"
        );
    }

    private function handleKeywordInput(string $lineUserId, array $user, array $session, string $text, string $replyToken): void {
        if (!$this->ensureCanGenerateNow($lineUserId, $replyToken)) return;

        if (mb_strlen($text) < 2) {
            $this->line->replyText($replyToken, "もう少し詳しく教えてください🙏\n例：月、少女、森");
            return;
        }

        // NGワードチェック
        require_once BASE_PATH . '/app/Services/ContentFilter.php';
        if (!ContentFilter::isSafe($text)) {
            $this->line->replyText($replyToken,
                "申し訳ありません。その内容では画像を作成できません🙏\n別の表現でお試しください。");
            Logger::info('line', "NGワード検出（キーワード） line={$lineUserId}");
            return;
        }

        $surveyData = $session['survey_data'];
        $styleKey   = $surveyData['style'] ?? 'any_style';
        $moodKey    = $surveyData['mood']  ?? 'any_mood';

        // セッションを閉じる
        $this->session->clear($lineUserId);

        // 依頼保存
        $requestId = $this->insertTenantRecord('image_requests', [
            'user_id' => (int)$user['id'],
            'line_user_id' => $lineUserId,
            'input_type' => 'survey',
            'input_text' => $text,
            'survey_style' => $styleKey,
            'survey_mood' => $moodKey,
            'status' => 'received',
        ]);
        $this->queueGeneration($requestId);

        Logger::info('line', "アンケート依頼受付 style={$styleKey} mood={$moodKey} request_id={$requestId}", $requestId);

        $styleLabel = SurveyDefinition::styleLabel($styleKey);
        $moodLabel  = SurveyDefinition::moodLabel($moodKey);
        $perPattern = max(1, min(4, (int)Settings::get('images_per_pattern', '4')));
        $total = min(max(1, Settings::maxImagesPerRequest()), $perPattern * 2);
        $countA = min($perPattern, (int)ceil($total / 2));
        $countB = max(0, $total - $countA);
        $breakdown = $countB > 0
            ? "Aパターン{$countA}枚・Bパターン{$countB}枚"
            : "Aパターン{$countA}枚";

        $this->line->replyText($replyToken,
            "ありがとうございます！画像生成を始めます🎨\n\n" .
            "【画風】{$styleLabel}\n" .
            "【雰囲気】{$moodLabel}\n" .
            "【キーワード】{$text}\n\n" .
            "{$breakdown}、合計{$total}枚を作成中です。\n完成したらこのLINEにお送りします。"
        );
    }

    // ---- Q送信ヘルパー ----

    private function askStyle(string $replyToken, string $prefix = ''): void {
        $msg = ($prefix ? $prefix . "\n\n" : '') .
               "Q1｜どんな画風がいいですか？\n（下から選んでください）";
        $this->line->replyWithQuickReply($replyToken, $msg,
            SurveyDefinition::quickReplyItems(SurveyDefinition::STYLES)
        );
    }

    private function askMood(string $replyToken, ?string $styleLabel = null, string $prefix = ''): void {
        $selected = $styleLabel ? "画風：{$styleLabel} ✅\n\n" : '';
        $msg = ($prefix ? $prefix . "\n\n" : '') .
               $selected .
               "Q2｜どんな雰囲気にしますか？\n（下から選んでください）";
        $this->line->replyWithQuickReply($replyToken, $msg,
            SurveyDefinition::quickReplyItems(SurveyDefinition::MOODS)
        );
    }

    private function askKeyword(string $replyToken, string $styleKey, string $moodKey): void {
        $styleLabel = SurveyDefinition::styleLabel($styleKey);
        $moodLabel  = SurveyDefinition::moodLabel($moodKey);
        $this->line->replyText($replyToken,
            "画風：{$styleLabel} ✅\n雰囲気：{$moodLabel} ✅\n\n" .
            "Q3｜最後に、描きたいものを教えてください✏️\n\n" .
            "キーワード（例：月、少女、森）\nまたは文章（例：夕暮れの海辺を歩く少年）\n\n" .
            "※「キャンセル」で最初に戻れます"
        );
    }

    // ---- ユーティリティ ----

    // 選択肢テキストからキーを逆引き（ラベルの部分一致）
    private function matchChoice(string $text, array $choices): ?string {
        // 完全一致優先
        foreach ($choices as $key => $label) {
            if ($text === $label) return $key;
        }
        // 絵文字を除いた部分一致
        foreach ($choices as $key => $label) {
            $plain = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', '', $label);
            $plain = trim($plain);
            if (mb_strpos($text, $plain) !== false || mb_strpos($plain, $text) !== false) {
                return $key;
            }
        }
        return null;
    }

    private function checkDailyLimit(string $lineUserId, string $replyToken): bool {
        if ($this->generationTestAccess->isEnabledForLineUserId($lineUserId)) {
            return true;
        }

        $accessMode = Settings::get('generation_access_mode', 'class_attendance');
        $classMode = Settings::get('class_mode_enabled', '1') === '1' && $accessMode === 'class_attendance';
        $maxDaily = $classMode ? $this->classSvc->getTodayMaxRequests($lineUserId) : Settings::maxDailyPerUser();
        if ($maxDaily <= 0) {
            $maxDaily = Settings::maxDailyPerUser();
        }
        [$tenantWhere, $tenantParams] = $this->tenantWhereFor('image_requests');
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM image_requests
            WHERE line_user_id = ?
              AND DATE(created_at) = CURDATE()
              AND COALESCE(status, '') NOT IN ('failed', 'cancelled', 'canceled', 'deleted')
              {$tenantWhere}
        ");
        $stmt->execute(array_merge([$lineUserId], $tenantParams));
        $count = (int) $stmt->fetchColumn();
        $count = $this->applyDailyUsageOverride($lineUserId, $count);

        if ($count >= $maxDaily) {
            $this->line->replyText($replyToken,
                "本日の依頼数（{$maxDaily}件）に達しました。\n明日またお試しください🙏"
            );
            return false;
        }
        return true;
    }

    private function checkPeriodLimit(string $lineUserId, string $replyToken): bool {
        if ($this->generationTestAccess->isEnabledForLineUserId($lineUserId)) {
            return true;
        }

        $limit = (int)Settings::get('generation_period_request_limit', '0');
        if ($limit <= 0) {
            return true;
        }

        $start = $this->normalizeDateSetting(Settings::get('generation_available_date_start', ''));
        $end = $this->normalizeDateSetting(Settings::get('generation_available_date_end', ''));
        if ($start === '' && $end === '') {
            return true;
        }

        [$tenantWhere, $tenantParams] = $this->tenantWhereFor('image_requests');
        $where = "line_user_id = ? AND COALESCE(status, '') NOT IN ('failed', 'cancelled', 'canceled', 'deleted') {$tenantWhere}";
        $params = array_merge([$lineUserId], $tenantParams);
        if ($start !== '') {
            $where .= " AND DATE(created_at) >= ?";
            $params[] = $start;
        }
        if ($end !== '') {
            $where .= " AND DATE(created_at) <= ?";
            $params[] = $end;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM image_requests WHERE {$where}");
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();
        if ($count < $limit) {
            return true;
        }

        $range = ($start !== '' || $end !== '') ? (($start ?: '...') . ' - ' . ($end ?: '...')) : '設定期間';
        $this->line->replyText($replyToken, "この期間の生成上限に達しました。\n上限：{$limit}件\n期間：{$range}");
        return false;
    }

    private function shouldRequireClassAttendanceGate(string $lineUserId): bool {
        if ($this->generationTestAccess->isEnabledForLineUserId($lineUserId)) {
            return false;
        }

        $mode = Settings::get('generation_access_mode', 'class_attendance');
        if ($mode === '') {
            $mode = Settings::get('class_mode_enabled', '1') === '1' ? 'class_attendance' : 'always_open';
        }
        if ($mode === 'always_open' || $mode === 'time_window_only') {
            return false;
        }
        if ($mode === 'class_or_time_window' && $this->generationWindowStatus()['open']) {
            return false;
        }
        return true;
    }

    private function ensureCanGenerateNow(string $lineUserId, string $replyToken): bool {
        if ($this->generationTestAccess->isEnabledForLineUserId($lineUserId)) {
            return true;
        }

        if (Settings::get('generation_online_enabled', '1') !== '1') {
            $this->line->replyText($replyToken, '現在、オンライン生成は停止中です。運営者にお問い合わせください。');
            return false;
        }

        if (!$this->ensureGenerationDateOpen($replyToken)) {
            return false;
        }
        if (!$this->ensureGenerationWeekdayOpen($replyToken)) {
            return false;
        }
        if (!$this->checkPeriodLimit($lineUserId, $replyToken)) {
            return false;
        }

        $mode = Settings::get('generation_access_mode', 'class_attendance');
        if ($mode === '') {
            $mode = Settings::get('class_mode_enabled', '1') === '1' ? 'class_attendance' : 'always_open';
        }

        if ($mode === 'always_open') {
            return true;
        }

        if ($mode === 'time_window_only') {
            return $this->ensureGenerationWindowOpen($replyToken);
        }

        if ($mode === 'class_or_time_window') {
            if ($this->hasCheckedInToday($lineUserId)) {
                return true;
            }
            return $this->ensureGenerationWindowOpen($replyToken);
        }

        if (Settings::get('class_mode_enabled', '1') !== '1') {
            return true;
        }
        if ($this->hasCheckedInToday($lineUserId)) {
            return true;
        }
        $this->line->replyWithQuickReply($replyToken,
            '画像生成は、当日の参加確認後に利用できます。',
            [
                ['type'=>'action','action'=>['type'=>'message','label'=>'参加','text'=>'参加']],
                ['type'=>'action','action'=>['type'=>'message','label'=>'マイページ','text'=>'マイページ']],
            ]
        );
        return false;
    }

    private function ensureGenerationDateOpen(string $replyToken): bool {
        $start = $this->normalizeDateSetting(Settings::get('generation_available_date_start', ''));
        $end = $this->normalizeDateSetting(Settings::get('generation_available_date_end', ''));
        $today = date('Y-m-d');

        if ($start !== '' && $today < $start) {
            $this->line->replyText($replyToken, "画像生成の受付開始前です。\n開始日：{$start}");
            return false;
        }
        if ($end !== '' && $today > $end) {
            $this->line->replyText($replyToken, "画像生成の受付期間は終了しました。\n終了日：{$end}");
            return false;
        }
        return true;
    }

    private function ensureGenerationWeekdayOpen(string $replyToken): bool {
        $raw = trim((string)Settings::get('generation_available_weekdays', ''));
        if ($raw === '') {
            return true;
        }

        $allowed = array_filter(array_map('trim', explode(',', strtolower($raw))));
        if (!$allowed) {
            return true;
        }

        $map = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $today = $map[(int)date('w')] ?? '';
        if (in_array($today, $allowed, true) || in_array((string)date('w'), $allowed, true)) {
            return true;
        }

        $this->line->replyText($replyToken, "本日は画像生成の受付日ではありません。\n受付曜日：{$raw}");
        return false;
    }

    private function normalizeDateSetting(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        return $value;
    }

    private function ensureGenerationWindowOpen(string $replyToken): bool {
        $status = $this->generationWindowStatus();
        if ($status['open']) {
            return true;
        }

        $message = Settings::get('generation_window_message', '');
        if ($message === '') {
            if ($status['configured']) {
                $message = "現在は画像生成の受付時間外です。\n受付時間：{$status['start']}〜{$status['end']}\n受付時間になってから「生成する」を押してください。";
            } else {
                $message = "画像生成の受付時間が未設定です。管理者にお問い合わせください。";
            }
        }

        $this->line->replyWithQuickReply($replyToken, $message, [
            ['type'=>'action','action'=>['type'=>'message','label'=>'生成する','text'=>'生成する']],
            ['type'=>'action','action'=>['type'=>'message','label'=>'マイページ','text'=>'マイページ']],
        ]);
        return false;
    }

    private function generationWindowStatus(): array {
        $start = substr(trim((string)Settings::get('generation_window_start', '')), 0, 5);
        $end = substr(trim((string)Settings::get('generation_window_end', '')), 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            return ['configured' => false, 'open' => false, 'start' => $start, 'end' => $end];
        }

        $now = date('H:i');
        if ($start <= $end) {
            $open = ($now >= $start && $now <= $end);
        } else {
            $open = ($now >= $start || $now <= $end);
        }

        return ['configured' => true, 'open' => $open, 'start' => $start, 'end' => $end];
    }

    private function hasCheckedInToday(string $lineUserId): bool {
        try {
            [$tenantWhere, $tenantParams] = $this->attendanceTenantWhere('a', 's');
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM class_attendances a
                INNER JOIN class_schedules s ON s.id = a.schedule_id
                WHERE a.line_user_id = ?
                  AND s.class_date = CURDATE()
                  AND a.status = 'approved'
                  AND a.attended_at IS NOT NULL
                  AND s.status IN ('scheduled','active')
                  {$tenantWhere}
            ");
            $stmt->execute(array_merge([$lineUserId], $tenantParams));
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            Logger::error('line', 'failed to check generation attendance gate: ' . $e->getMessage());
            return false;
        }
    }

    private function applyDailyUsageOverride(string $lineUserId, int $actualCount): int {
        try {
            $this->ensureGenerationUsageOverrideTable();
            [$tenantWhere, $tenantParams] = $this->tenantWhereFor('image_request_usage_overrides');
            $stmt = $this->pdo->prepare("
                SELECT override_count
                FROM image_request_usage_overrides
                WHERE line_user_id = ?
                  AND usage_date = CURDATE()
                  {$tenantWhere}
                ORDER BY updated_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute(array_merge([$lineUserId], $tenantParams));
            $override = $stmt->fetchColumn();
            if ($override !== false && $override !== null) {
                return max(0, (int)$override);
            }
        } catch (Throwable $e) {
            Logger::error('line', 'failed to apply generation usage override: ' . $e->getMessage());
        }
        return $actualCount;
    }

    private function ensureGenerationUsageOverrideTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS image_request_usage_overrides (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                line_user_id VARCHAR(255) NOT NULL,
                usage_date DATE NOT NULL,
                override_count INT NOT NULL DEFAULT 0,
                memo TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_user_usage_date (user_id, usage_date),
                KEY idx_line_user_date (line_user_id, usage_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        try {
            $this->pdo->exec("ALTER TABLE image_request_usage_overrides ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id");
        } catch (Throwable $e) {}
        try {
            $this->pdo->exec("ALTER TABLE image_request_usage_overrides ADD INDEX idx_image_request_usage_overrides_tenant_id (tenant_id)");
        } catch (Throwable $e) {}
        if (
            $this->tenant->tenantId()
            && $this->tenant->hasTenantColumn('image_request_usage_overrides')
            && $this->tenant->isDefaultTenant()
        ) {
            try {
                $stmt = $this->pdo->prepare("UPDATE image_request_usage_overrides SET tenant_id = ? WHERE tenant_id IS NULL");
                $stmt->execute([(int)$this->tenant->tenantId()]);
            } catch (Throwable $e) {}
        }
    }

    private function handleImageMessage(string $lineUserId, array $event): void {
        $replyToken = $event['replyToken'] ?? '';
        $messageId = $event['message']['id'] ?? '';
        if ($messageId === '') return;

        $user = $this->upsertUser($lineUserId);
        if (Settings::get('photo_illustration_enabled', '1') !== '1') {
            $this->line->replyText($replyToken, "現在、写真イラスト化機能は停止中です。\n通常の画像生成は「生成する」と送ると利用できます。");
            return;
        }

        if (!$this->ensureCanGenerateNow($lineUserId, $replyToken)) return;
        if (!$this->checkDailyLimit($lineUserId, $replyToken)) return;
        $this->cleanupOldSourcePhotos();

        $binary = $this->line->getMessageContent($messageId);
        if ($binary === null) {
            $this->line->replyText($replyToken, "写真を取得できませんでした。もう一度送ってください。");
            return;
        }

        require_once BASE_PATH . '/app/Services/StorageService.php';
        $storage = new StorageService();
        $relative = 'source_photos/' . date('Ymd') . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $lineUserId) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '', $messageId) . '.jpg';
        $sourcePath = $storage->savePrivate($binary, $relative);

        $this->session->advance($lineUserId, 'photo_style', [
            'source_image_message_id' => $messageId,
            'source_image_path' => $sourcePath,
        ]);

        $intro = Settings::get('photo_illustration_intro', "写真を受け取りました。\nどの雰囲気でイラスト化しますか？");
        $consent = Settings::get('photo_illustration_consent', "送信された写真はイラスト生成のために使用します。\n本人または利用許可を得た写真のみ送ってください。");
        $retention = (int)Settings::get('photo_illustration_retention_days', '14');
        $message = trim($intro) . "\n\n" . trim($consent) . "\n\n保存期間の目安：{$retention}日\n\nどの雰囲気でイラスト化しますか？";
        $this->line->replyWithQuickReply($replyToken, $message, $this->photoStyleQuickReplies());
    }

    private function handlePhotoStyleAnswer(string $lineUserId, array $user, array $session, string $text, string $replyToken): void {
        if (!$this->ensureCanGenerateNow($lineUserId, $replyToken)) return;

        $styleKey = $this->matchPhotoStyle($text);
        if ($styleKey === null) {
            $this->line->replyWithQuickReply($replyToken, "下のボタンからイラストの雰囲気を選んでください。", $this->photoStyleQuickReplies());
            return;
        }

        $data = $session['survey_data'] ?? [];
        $sourcePath = (string)($data['source_image_path'] ?? '');
        $messageId = (string)($data['source_image_message_id'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath)) {
            $this->session->clear($lineUserId);
            $this->line->replyText($replyToken, "元写真の保存データが見つかりませんでした。もう一度写真を送ってください。");
            return;
        }

        $this->ensurePhotoColumnsForWebhook();
        $label = $this->photoStyleLabel($styleKey);
        $requestId = $this->insertTenantRecord('image_requests', [
            'user_id' => (int)$user['id'],
            'line_user_id' => $lineUserId,
            'input_type' => 'photo_illustration',
            'input_text' => "写真イラスト化: {$label}",
            'source_image_message_id' => $messageId,
            'source_image_path' => $sourcePath,
            'photo_style' => $styleKey,
            'status' => 'received',
        ]);
        $this->queueGeneration($requestId);

        $this->session->clear($lineUserId);
        Logger::info('line', "photo illustration request_id={$requestId} style={$styleKey}", $requestId);
        $this->line->replyText($replyToken, "写真を「{$label}」でイラスト化します。\n完成までしばらくお待ちください。");
    }

    private function photoStyleQuickReplies(): array {
        $items = [];
        foreach ($this->photoStyleOptions() as $style) {
            $items[] = [
                'type' => 'action',
                'action' => [
                    'type' => 'message',
                    'label' => mb_substr($style['label'], 0, 20),
                    'text' => $style['label'],
                ],
            ];
        }
        return $items;
    }

    private function matchPhotoStyle(string $text): ?string {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        foreach ($this->photoStyleOptions() as $style) {
            if (mb_stripos($text, $style['label']) !== false || $text === $style['key']) {
                return $style['key'];
            }
        }

        $aliases = [
            'anime' => ['アニメ', 'anime'],
            'watercolor' => ['水彩'],
            'picture_book' => ['絵本'],
            'sns_icon' => ['SNS', 'アイコン', 'sns'],
            'japanese' => ['和風', '日本'],
        ];
        foreach ($aliases as $key => $words) {
            foreach ($words as $word) {
                if (mb_stripos($text, $word) !== false) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function photoStyleLabel(string $styleKey): string {
        foreach ($this->photoStyleOptions() as $style) {
            if ($style['key'] === $styleKey) {
                return $style['label'];
            }
        }

        $labels = [
            'anime' => 'アニメ風',
            'watercolor' => '水彩風',
            'picture_book' => '絵本風',
            'sns_icon' => 'SNSアイコン',
            'japanese' => '和風',
        ];
        return $labels[$styleKey] ?? 'アニメ風';
    }

    private function photoStyleOptions(): array {
        $raw = trim((string)Settings::get('photo_illustration_styles', ''));
        $labels = $raw !== '' ? preg_split('/\r\n|\r|\n/', $raw) : [];
        $labels = array_values(array_filter(array_map('trim', $labels ?: [])));
        if (!$labels) {
            $labels = ['アニメ風', '水彩風', '絵本風', 'SNSアイコン', '和風'];
        }

        $known = [
            'アニメ風' => 'anime',
            '水彩風' => 'watercolor',
            '絵本風' => 'picture_book',
            'SNSアイコン' => 'sns_icon',
            '和風' => 'japanese',
        ];

        $items = [];
        foreach (array_slice($labels, 0, 8) as $index => $label) {
            $items[] = [
                'key' => $known[$label] ?? ('custom_' . ($index + 1)),
                'label' => mb_substr($label, 0, 40),
            ];
        }

        return $items;
    }
    private function ensurePhotoColumnsForWebhook(): void {
        $columns = [
            "ALTER TABLE image_requests ADD COLUMN source_image_message_id VARCHAR(255) NULL AFTER input_text",
            "ALTER TABLE image_requests ADD COLUMN source_image_path TEXT NULL AFTER source_image_message_id",
            "ALTER TABLE image_requests ADD COLUMN source_image_url TEXT NULL AFTER source_image_path",
            "ALTER TABLE image_requests ADD COLUMN photo_style VARCHAR(50) NULL AFTER source_image_url",
        ];
        foreach ($columns as $sql) {
            try { $this->pdo->exec($sql); } catch (\Throwable $e) {}
        }
    }

    private function cleanupOldSourcePhotos(): void {
        $days = max(1, min(90, (int)Settings::get('photo_illustration_retention_days', '14')));
        $base = STORAGE_PATH . '/source_photos';
        if (!is_dir($base)) return;
        $limit = time() - ($days * 86400);
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            if ($item->isFile() && (int)$item->getMTime() < $limit) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
    }

    private function upsertUser(string $lineUserId): array {
        if (
            $this->tenant->active()
            && !$this->tenant->isDefaultTenant()
            && !$this->tenant->hasTenantColumn('users')
        ) {
            throw new RuntimeException('users table is not tenant-ready.');
        }
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE line_user_id = ?" . $this->tenant->andWhere('users') . " LIMIT 1");
        $stmt->execute(array_merge([$lineUserId], $this->tenant->params('users')));
        $user = $stmt->fetch();
        if ($user) {
            if (!empty($user['id'])) {
                $this->assignTenantToRow('users', (int)$user['id']);
                CommonIntegrationService::registerSafely((int)$user['id'], $lineUserId);
            }
            return $user;
        }

        $profile = $this->line->getProfile($lineUserId);
        if ($this->tenant->hasTenantColumn('users') && $this->tenant->tenantId()) {
            $this->pdo->prepare("
                INSERT INTO users (tenant_id, line_user_id, display_name, picture_url, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'active', NOW(), NOW())
                ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), updated_at = NOW()
            ")->execute([
                (int)$this->tenant->tenantId(),
                $lineUserId,
                $profile['displayName'] ?? 'Unknown',
                $profile['pictureUrl']  ?? null,
            ]);
        } else {
            $this->pdo->prepare("
                INSERT INTO users (line_user_id, display_name, picture_url, status, created_at, updated_at)
                VALUES (?, ?, ?, 'active', NOW(), NOW())
                ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), updated_at = NOW()
            ")->execute([
                $lineUserId,
                $profile['displayName'] ?? 'Unknown',
                $profile['pictureUrl']  ?? null,
            ]);
        }

        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE line_user_id = ?" . $this->tenant->andWhere('users') . " LIMIT 1");
        $stmt->execute(array_merge([$lineUserId], $this->tenant->params('users')));
        $user = $stmt->fetch();
        if ($user && !empty($user['id'])) {
            $this->assignTenantToRow('users', (int)$user['id']);
            CommonIntegrationService::registerSafely((int)$user['id'], $lineUserId);
        }
        return $user;
    }

    private function tenantQueryString(): string {
        $tenant = Settings::currentTenant();
        $key = trim((string)($tenant['tenant_key'] ?? ''));
        return $key !== '' ? '?tenant=' . rawurlencode($key) : '';
    }
}


