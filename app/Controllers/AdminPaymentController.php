<?php
// app/Controllers/AdminPaymentController.php
// 決済履歴・返金

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/PaymentLog.php';
require_once BASE_PATH . '/app/Services/ReservationEventLog.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class AdminPaymentController {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function index(): void {
        $filter   = $_GET['kind'] ?? '';
        $status   = $_GET['status'] ?? '';
        if (!in_array($status, ['', 'paid', 'refunded'], true)) {
            $status = '';
        }
        $payments = PaymentLog::recent($this->pdo, 200, $filter, $status);
        $summary  = PaymentLog::summary($this->pdo);
        require BASE_PATH . '/app/Views/admin/payments.php';
    }

    // 返金
    public function refund(int $id): void {
        require_once BASE_PATH . '/app/Services/StripeService.php';
        require_once BASE_PATH . '/app/Services/AuditLog.php';

        $stmt = $this->pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?" . $this->tenant->andWhere('payment_transactions'));
        $stmt->execute(array_merge([$id], $this->tenant->params('payment_transactions')));
        $p = $stmt->fetch();

        if (!$p || $p['status'] !== 'paid') {
            header('Location: /admin/payments?error=1');
            exit;
        }

        $stripe = new StripeService();
        $ok = false;
        if (!empty($p['stripe_session_id'])) {
            $ok = $stripe->refundBySession($p['stripe_session_id']);
        }

        if ($ok) {
            PaymentLog::markRefunded($this->pdo, $id);
            if (($p['kind'] ?? '') === 'attendance' && !empty($p['stripe_session_id'])) {
                $this->pdo->prepare("
                    UPDATE class_attendances
                    SET payment_status = 'refunded', updated_at = NOW()
                    WHERE stripe_session_id = ?" . $this->tenant->andWhere('class_attendances') . "
                ")->execute(array_merge([$p['stripe_session_id']], $this->tenant->params('class_attendances')));

                $stmt = $this->pdo->prepare("SELECT * FROM class_attendances WHERE stripe_session_id = ?" . $this->tenant->andWhere('class_attendances') . " LIMIT 1");
                $stmt->execute(array_merge([$p['stripe_session_id']], $this->tenant->params('class_attendances')));
                $attendance = $stmt->fetch() ?: [];
                ReservationEventLog::record($this->pdo, [
                    'attendance_id' => $attendance['id'] ?? null,
                    'schedule_id' => $attendance['schedule_id'] ?? null,
                    'user_id' => $attendance['user_id'] ?? ($p['user_id'] ?? null),
                    'line_user_id' => $attendance['line_user_id'] ?? ($p['line_user_id'] ?? null),
                    'event_type' => 'refunded',
                    'payment_status' => 'refunded',
                    'amount' => $p['amount'] ?? 0,
                    'message' => 'Manual refund from admin payments page.',
                ]);
            } else {
                ReservationEventLog::record($this->pdo, [
                    'attendance_id' => null,
                    'schedule_id' => null,
                    'user_id' => $p['user_id'] ?? null,
                    'line_user_id' => $p['line_user_id'] ?? null,
                    'event_type' => 'refunded',
                    'payment_status' => 'refunded',
                    'amount' => $p['amount'] ?? 0,
                    'message' => 'Manual refund from admin payments page.',
                ]);
            }
            // チケットの場合は付与分を戻す処理は運用判断（ここでは記録のみ）
            AuditLog::record('refund', "payment_id={$id}", "{$p['amount']}円");
            header('Location: /admin/payments?refunded=1');
        } else {
            ReservationEventLog::record($this->pdo, [
                'attendance_id' => null,
                'schedule_id' => null,
                'user_id' => $p['user_id'] ?? null,
                'line_user_id' => $p['line_user_id'] ?? null,
                'event_type' => 'refund_failed',
                'payment_status' => $p['status'] ?? '',
                'amount' => $p['amount'] ?? 0,
                'message' => 'Manual refund failed from admin payments page.',
            ]);
            header('Location: /admin/payments?error=1');
        }
        exit;
    }
}
