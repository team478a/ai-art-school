<?php
// app/Controllers/StripeWebhookController.php
// Handles Stripe checkout events and keeps access details for day-of notices.

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/StripeService.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/Logger.php';
require_once BASE_PATH . '/app/Services/RichMenuSegmentService.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';
require_once BASE_PATH . '/app/Services/ShoppingIntegrationService.php';

class StripeWebhookController {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function handle(): void {
        $shopping = new ShoppingIntegrationService($this->pdo);
        if ($shopping->isActive()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'ignored' => 'shopping_provider_active',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        $stripe = new StripeService();
        $event = $stripe->verifyWebhook($payload, $sigHeader);

        if (!$event) {
            http_response_code(400);
            echo 'invalid';
            return;
        }

        $type = (string)($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];

        if ($type === 'checkout.session.completed') {
            $meta = $object['metadata'] ?? [];
            $kind = $meta['kind'] ?? 'attendance';

            if ($kind === 'ticket') {
                $this->confirmTicketPurchase(
                    (int)($meta['user_id'] ?? 0),
                    (int)($meta['ticket_count'] ?? 0),
                    (string)($object['id'] ?? ''),
                    (int)($object['amount_total'] ?? 0)
                );
            } elseif ($kind === 'subscription' || ($object['mode'] ?? '') === 'subscription') {
                $this->confirmSubscription(
                    (int)($meta['user_id'] ?? 0),
                    (string)($object['customer'] ?? ''),
                    (string)($object['subscription'] ?? '')
                );
            } else {
                $attendanceId = (int)($meta['attendance_id'] ?? 0);
                if ($attendanceId > 0) {
                    $this->confirmPayment($attendanceId, (string)($object['id'] ?? ''));
                }
            }
        }

        if ($type === 'customer.subscription.deleted') {
            $this->endSubscription((string)($object['id'] ?? ''));
        }

        if ($type === 'invoice.payment_failed') {
            $this->handlePaymentFailed((string)($object['subscription'] ?? ''));
        }

        http_response_code(200);
        echo 'ok';
    }

    private function confirmTicketPurchase(int $userId, int $count, string $sessionId = '', int $amount = 0): void {
        if ($userId <= 0 || $count <= 0) {
            return;
        }

        require_once BASE_PATH . '/app/Services/PaymentLog.php';
        if ($sessionId !== '' && PaymentLog::existsByStripeSessionId($sessionId)) {
            Logger::info('stripe', "ticket duplicate session={$sessionId}");
            return;
        }

        require_once BASE_PATH . '/app/Services/BillingService.php';
        (new BillingService())->addTickets($userId, $count);

        $stmt = $this->pdo->prepare("SELECT line_user_id, ticket_balance FROM users WHERE id = ?" . $this->tenant->andWhere('users'));
        $stmt->execute(array_merge([$userId], $this->tenant->params('users')));
        $user = $stmt->fetch();

        PaymentLog::record([
            'user_id' => $userId,
            'line_user_id' => $user['line_user_id'] ?? null,
            'kind' => 'ticket',
            'amount' => $amount,
            'status' => 'paid',
            'description' => "回数券{$count}回分",
            'stripe_session_id' => $sessionId,
        ]);

        if ($user && !empty($user['line_user_id'])) {
            $balance = (int)($user['ticket_balance'] ?? 0);
            (new LineService())->pushText(
                $user['line_user_id'],
                "回数券{$count}回分のご購入ありがとうございます。\n現在の残り：{$balance}回\n\n教室参加時に自動で使用されます。"
            );
        }

        $this->syncRichMenuForUser((string)($user['line_user_id'] ?? ''));
        Logger::info('stripe', "ticket confirmed user={$userId} count={$count}");
    }

    private function confirmSubscription(int $userId, string $customerId, string $subscriptionId): void {
        if ($userId <= 0) {
            return;
        }

        $this->pdo->prepare("
            UPDATE users
            SET member_type = 'subscriber',
                stripe_customer_id = ?,
                stripe_subscription_id = ?,
                updated_at = NOW()
            WHERE id = ?" . $this->tenant->andWhere('users') . "
        ")->execute(array_merge([$customerId, $subscriptionId, $userId], $this->tenant->params('users')));

        $stmt = $this->pdo->prepare("SELECT line_user_id FROM users WHERE id = ?" . $this->tenant->andWhere('users'));
        $stmt->execute(array_merge([$userId], $this->tenant->params('users')));
        $user = $stmt->fetch();

        require_once BASE_PATH . '/app/Services/PaymentLog.php';
        PaymentLog::record([
            'user_id' => $userId,
            'line_user_id' => $user['line_user_id'] ?? null,
            'kind' => 'subscription',
            'amount' => 0,
            'status' => 'paid',
            'description' => 'サブスク加入',
            'stripe_session_id' => $subscriptionId,
        ]);

        if ($user && !empty($user['line_user_id'])) {
            (new LineService())->pushText(
                $user['line_user_id'],
                "サブスク会員への登録ありがとうございます。\n\n教室は無料で参加できます。参加予約からお申し込みください。"
            );
        }

        $this->syncRichMenuForUser((string)($user['line_user_id'] ?? ''));
        Logger::info('stripe', "subscription confirmed user={$userId} sub={$subscriptionId}");
    }

    private function handlePaymentFailed(string $subscriptionId): void {
        if ($subscriptionId === '') {
            return;
        }

        $stmt = $this->pdo->prepare("SELECT id, line_user_id FROM users WHERE stripe_subscription_id = ?" . $this->tenant->andWhere('users'));
        $stmt->execute(array_merge([$subscriptionId], $this->tenant->params('users')));
        $user = $stmt->fetch();
        if (!$user || empty($user['line_user_id'])) {
            return;
        }

        (new LineService())->pushText(
            $user['line_user_id'],
            "サブスクのお支払いを確認できませんでした。\nカードの有効期限切れなどが考えられます。お手数ですが、お支払い方法をご確認ください。"
        );

        Logger::info('stripe', "subscription payment failed user={$user['id']}");
    }

    private function endSubscription(string $subscriptionId): void {
        if ($subscriptionId === '') {
            return;
        }

        $stmt = $this->pdo->prepare("SELECT id, line_user_id FROM users WHERE stripe_subscription_id = ?" . $this->tenant->andWhere('users'));
        $stmt->execute(array_merge([$subscriptionId], $this->tenant->params('users')));
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        $this->pdo->prepare("
            UPDATE users
            SET member_type = 'none', stripe_subscription_id = NULL, updated_at = NOW()
            WHERE id = ?" . $this->tenant->andWhere('users') . "
        ")->execute(array_merge([(int)$user['id']], $this->tenant->params('users')));

        if (!empty($user['line_user_id'])) {
            (new LineService())->pushText(
                $user['line_user_id'],
                "サブスク会員の解約手続きが完了しました。\nまたのご利用をお待ちしております。"
            );
        }

        $this->syncRichMenuForUser((string)($user['line_user_id'] ?? ''));
        Logger::info('stripe', "subscription ended user={$user['id']}");
    }

    private function confirmPayment(int $attendanceId, string $sessionId): void {
        $scheduleJoin = $this->tenantJoinFilter('class_schedules', 's');
        $stmt = $this->pdo->prepare("
            SELECT a.*, s.title, s.class_date, s.start_time, s.end_time, s.max_requests
            FROM class_attendances a
            INNER JOIN class_schedules s ON s.id = a.schedule_id{$scheduleJoin}
            WHERE a.id = ?" . $this->tenant->andWhere('class_attendances', 'a') . "
        ");
        $stmt->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));
        $attendance = $stmt->fetch();
        if (!$attendance) {
            return;
        }

        if (($attendance['payment_status'] ?? '') === 'paid') {
            return;
        }

        $this->pdo->prepare("
            UPDATE class_attendances
            SET status = 'approved',
                payment_status = 'paid',
                paid_at = NOW(),
                approved_at = COALESCE(approved_at, NOW()),
                notified_at = NOW(),
                updated_at = NOW()
            WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
        ")->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));

        $date = $this->formatDate((string)$attendance['class_date']);
        $start = substr((string)($attendance['start_time'] ?? ''), 0, 5);
        $end = substr((string)($attendance['end_time'] ?? ''), 0, 5);
        $time = $start . ($end !== '' ? '-' . $end : '');
        $maxReq = (int)($attendance['max_requests'] ?? 2);

        if (!empty($attendance['line_user_id'])) {
            (new LineService())->pushText(
                $attendance['line_user_id'],
                "お支払いが完了し、予約が確定しました。\n\n" .
                "{$attendance['title']}\n{$date} {$time}\n\n" .
                "当日のZoom URLまたは会場の場所は、開催当日にLINEでご案内します。\n" .
                "複数の教室を予約している場合も、当日の対象教室だけを案内します。\n\n" .
                "当日は{$maxReq}件まで画像生成できます。"
            );
        }

        require_once BASE_PATH . '/app/Services/PaymentLog.php';
        PaymentLog::record([
            'user_id' => $attendance['user_id'],
            'line_user_id' => $attendance['line_user_id'],
            'kind' => 'attendance',
            'amount' => (int)$attendance['payment_amount'],
            'status' => 'paid',
            'description' => $attendance['title'] . ' 参加費',
            'stripe_session_id' => $sessionId,
        ]);

        require_once BASE_PATH . '/app/Services/AdminNotifier.php';
        AdminNotifier::notify(
            'payment',
            "「{$attendance['title']}」の参加費 {$attendance['payment_amount']}円の決済が完了しました。"
        );

        Logger::info('stripe', "attendance payment confirmed attendance={$attendanceId}");
    }

    private function syncRichMenuForUser(string $lineUserId): void {
        if ($lineUserId === '') {
            return;
        }
        try {
            (new RichMenuSegmentService())->syncByLineUserId($lineUserId);
        } catch (Throwable $e) {
            Logger::warning('richmenu', 'stripe rich menu sync failed: ' . $e->getMessage());
        }
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }

    private function formatDate(string $date): string {
        $ts = strtotime($date);
        if (!$ts) {
            return $date;
        }
        return date('Y年n月j日', $ts);
    }
}
