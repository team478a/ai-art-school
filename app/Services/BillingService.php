<?php
// app/Services/BillingService.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/TicketLog.php';
require_once BASE_PATH . '/app/Services/ReservationEventLog.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class BillingService {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function attendedCount(int $userId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
              FROM class_attendances
             WHERE user_id = ?" . $this->tenant->andWhere('class_attendances') . "
               AND (
                    attended_at IS NOT NULL
                    OR status = 'attended'
               )
        ");
        $stmt->execute(array_merge([$userId], $this->tenant->params('class_attendances')));
        return (int)$stmt->fetchColumn();
    }

    public function judge(array $user, array $schedule): array {
        $fee = (int)($schedule['fee'] ?? 0);

        if ($fee <= 0) {
            return ['type' => 'free', 'amount' => 0, 'message' => '無料'];
        }

        if ($this->isFirstVisitFree((int)$user['id'])) {
            return ['type' => 'free', 'amount' => 0, 'message' => '初回参加無料'];
        }

        if ($this->isActiveSubscriber($user)) {
            return ['type' => 'subscription', 'amount' => 0, 'message' => 'サブスク会員'];
        }

        if ($this->hasUsableTicket($user)) {
            return ['type' => 'ticket', 'amount' => 0, 'message' => '回数券利用'];
        }

        return ['type' => 'paid', 'amount' => $fee, 'message' => number_format($fee) . '円'];
    }

    public function applyToAttendance(int $attendanceId, int $userId, array $judge): void {
        $amount = (int)$judge['amount'];
        $status = ($judge['type'] === 'paid') ? 'unpaid' : $judge['type'];

        $this->pdo->prepare("
            UPDATE class_attendances
               SET payment_status = ?,
                   payment_amount = ?,
                   updated_at = NOW()
             WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
        ")->execute(array_merge([$status, $amount, $attendanceId], $this->tenant->params('class_attendances')));

        if ($judge['type'] === 'ticket') {
            $this->consumeTicket($userId, $attendanceId);
        }
    }

    public function markPaid(int $attendanceId): void {
        $stmt = $this->pdo->prepare("SELECT * FROM class_attendances WHERE id = ?" . $this->tenant->andWhere('class_attendances'));
        $stmt->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));
        $att = $stmt->fetch();

        $this->pdo->prepare("
            UPDATE class_attendances
               SET payment_status = 'paid',
                   paid_at = NOW(),
                   updated_at = NOW()
             WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
        ")->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));

        if ($att) {
            ReservationEventLog::record($this->pdo, [
                'attendance_id' => $attendanceId,
                'schedule_id' => $att['schedule_id'] ?? null,
                'user_id' => $att['user_id'] ?? null,
                'line_user_id' => $att['line_user_id'] ?? null,
                'event_type' => 'paid',
                'payment_status' => 'paid',
                'amount' => (int)($att['payment_amount'] ?? 0),
                'message' => '管理画面で支払い済みにしました。',
            ]);
        }
    }

    public function addTickets(int $userId, int $count, string $reason = 'manual', string $memo = ''): void {
        $days = (int)Settings::get('ticket_valid_days', '0');
        if ($count > 0 && $days > 0) {
            $this->pdo->prepare("
                UPDATE users
                   SET ticket_balance = COALESCE(ticket_balance, 0) + ?,
                       ticket_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY),
                       updated_at = NOW()
                 WHERE id = ?" . $this->tenant->andWhere('users') . "
            ")->execute(array_merge([$count, $days, $userId], $this->tenant->params('users')));
        } else {
            $this->pdo->prepare("
                UPDATE users
                   SET ticket_balance = GREATEST(0, COALESCE(ticket_balance, 0) + ?),
                       updated_at = NOW()
                 WHERE id = ?" . $this->tenant->andWhere('users') . "
            ")->execute(array_merge([$count, $userId], $this->tenant->params('users')));
        }

        TicketLog::record($this->pdo, [
            'user_id' => $userId,
            'change_count' => $count,
            'reason' => $reason,
            'memo' => $memo !== '' ? $memo : '回数券残数を変更',
        ]);
    }

    public function setMemberType(int $userId, string $type): void {
        $type = in_array($type, ['none', 'subscriber'], true) ? $type : 'none';
        $this->pdo->prepare("
            UPDATE users
               SET member_type = ?,
                   updated_at = NOW()
             WHERE id = ?" . $this->tenant->andWhere('users') . "
        ")->execute(array_merge([$type, $userId], $this->tenant->params('users')));
    }

    private function isFirstVisitFree(int $userId): bool {
        $setting = Settings::get('first_visit_free_enabled', '1');
        if ($setting === '0') {
            return false;
        }
        return $this->attendedCount($userId) === 0;
    }

    private function isActiveSubscriber(array $user): bool {
        $memberType = (string)($user['member_type'] ?? 'none');
        if (!in_array($memberType, ['subscriber', 'subscription', 'subscribed', 'monthly', 'annual'], true)) {
            return false;
        }

        $until = $user['subscription_until'] ?? null;
        return !$until || strtotime((string)$until) >= time();
    }

    private function hasUsableTicket(array $user): bool {
        if ((int)($user['ticket_balance'] ?? 0) <= 0) {
            return false;
        }

        $expiresAt = $user['ticket_expires_at'] ?? null;
        return !$expiresAt || strtotime((string)$expiresAt) >= time();
    }

    private function consumeTicket(int $userId, int $attendanceId): void {
        $this->pdo->prepare("
            UPDATE users
               SET ticket_balance = GREATEST(0, COALESCE(ticket_balance, 0) - 1),
                   updated_at = NOW()
             WHERE id = ?" . $this->tenant->andWhere('users') . "
        ")->execute(array_merge([$userId], $this->tenant->params('users')));

        TicketLog::record($this->pdo, [
            'user_id' => $userId,
            'change_count' => -1,
            'reason' => 'use',
            'related_attendance_id' => $attendanceId,
            'memo' => '教室参加で回数券を使用',
        ]);
    }
}
