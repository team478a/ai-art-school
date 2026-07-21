<?php
// app/Services/ClassScheduleService.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/ReservationEventLog.php';
require_once BASE_PATH . '/app/Services/TicketLog.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class ClassScheduleService {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->ensureReminderColumns();
    }

    // リマインダー用カラムを自動追加
    private function ensureReminderColumns(): void {
        try {
            // class_attendances に当日参加記録カラムを追加
            $acols = $this->pdo->query("SHOW COLUMNS FROM class_attendances")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('attended_at', $acols)) {
                $this->pdo->exec("ALTER TABLE class_attendances ADD COLUMN attended_at DATETIME NULL");
            }
            // 支払い記録カラム
            if (!in_array('payment_status', $acols)) {
                // unpaid（未集金）/ paid（集金済み）/ free（無料）/ subscription（サブスク）/ ticket（チケット利用）
                $this->pdo->exec("ALTER TABLE class_attendances ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
            }
            if (!in_array('payment_amount', $acols)) {
                $this->pdo->exec("ALTER TABLE class_attendances ADD COLUMN payment_amount INT NOT NULL DEFAULT 0");
            }
            if (!in_array('paid_at', $acols)) {
                $this->pdo->exec("ALTER TABLE class_attendances ADD COLUMN paid_at DATETIME NULL");
            }
            if (!in_array('stripe_session_id', $acols)) {
                $this->pdo->exec("ALTER TABLE class_attendances ADD COLUMN stripe_session_id VARCHAR(255) NULL");
            }

            // users に会員区分とチケット残数を追加
            $ucols = $this->pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('member_type', $ucols)) {
                // none（都度）/ subscriber（サブスク会員）
                $this->pdo->exec("ALTER TABLE users ADD COLUMN member_type VARCHAR(20) NOT NULL DEFAULT 'none'");
            }
            if (!in_array('ticket_balance', $ucols)) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN ticket_balance INT NOT NULL DEFAULT 0");
            }
            if (!in_array('stripe_customer_id', $ucols)) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN stripe_customer_id VARCHAR(255) NULL");
            }
            if (!in_array('stripe_subscription_id', $ucols)) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN stripe_subscription_id VARCHAR(255) NULL");
            }
            if (!in_array('subscription_until', $ucols)) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN subscription_until DATETIME NULL");
            }
            if (!in_array('ticket_expires_at', $ucols)) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN ticket_expires_at DATETIME NULL");
            }

            // class_schedules に料金カラム
            $cols = $this->pdo->query("SHOW COLUMNS FROM class_schedules")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('fee', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN fee INT NOT NULL DEFAULT 0");
            }
            $cols = $this->pdo->query("SHOW COLUMNS FROM class_schedules")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('reminder_at', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN reminder_at DATETIME NULL");
            }
            if (!in_array('reminder_message', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN reminder_message TEXT NULL");
            }
            if (!in_array('reminder_sent_at', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN reminder_sent_at DATETIME NULL");
            }
            if (!in_array('organizer', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN organizer VARCHAR(255) NULL");
            }
            if (!in_array('public_message', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN public_message TEXT NULL");
            }
            if (!in_array('event_format', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN event_format VARCHAR(20) NOT NULL DEFAULT 'realtime'");
            }
            if (!in_array('location', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN location VARCHAR(500) NULL");
            }
            if (!in_array('zoom_url', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN zoom_url VARCHAR(500) NULL");
            }
            if (!in_array('auto_approve', $cols)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN auto_approve TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (\Throwable $e) {
            // 失敗は無視
        }
    }

    // 今日の開催スケジュールを取得
    public function getTodaySchedule(): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM class_schedules
            WHERE class_date = CURDATE()
              AND status IN ('scheduled', 'active')" . $this->tenant->andWhere('class_schedules') . "
            ORDER BY start_time ASC
            LIMIT 1
        ");
        $stmt->execute($this->tenant->params('class_schedules'));
        return $stmt->fetch() ?: null;
    }

    // 現在チェックイン受付中かどうか
    public function isCheckinOpen(?array $schedule = null): bool {
        if (!Settings::get('class_mode_enabled', '1')) return true; // クラスモード無効なら常時開放
        $schedule = $schedule ?? $this->getTodaySchedule();
        if (!$schedule) return false;

        $now   = date('H:i:s');
        $open  = $schedule['checkin_open'];
        $close = $schedule['checkin_close'];
        return $now >= $open && $now <= $close;
    }

    // 今日の開催があるか（時間外含む）
    public function hasTodayClass(): bool {
        return $this->getTodaySchedule() !== null;
    }

    // 次回の開催日を取得
    public function getNextSchedule(): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM class_schedules
            WHERE class_date >= CURDATE()
              AND status IN ('scheduled', 'active')" . $this->tenant->andWhere('class_schedules') . "
            ORDER BY class_date ASC, start_time ASC
            LIMIT 1
        ");
        $stmt->execute($this->tenant->params('class_schedules'));
        return $stmt->fetch() ?: null;
    }

    // ユーザーの参加申請状況を取得
    public function getAttendance(int $scheduleId, string $lineUserId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM class_attendances
            WHERE schedule_id = ? AND line_user_id = ?" . $this->tenant->andWhere('class_attendances') . "
        ");
        $stmt->execute(array_merge([$scheduleId, $lineUserId], $this->tenant->params('class_attendances')));
        return $stmt->fetch() ?: null;
    }

    // 参加申請を作成
    public function applyAttendance(int $scheduleId, int $userId, string $lineUserId): array {
        if ($this->isUserBlocked($userId)) {
            return ['result' => 'blocked'];
        }

        // 定員チェック
        $schedule = $this->getScheduleById($scheduleId);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM class_attendances WHERE schedule_id = ? AND status IN ('pending','approved')" . $this->tenant->andWhere('class_attendances'));
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_attendances')));
        $approvedCount = (int) $stmt->fetchColumn();

        if ($schedule && $approvedCount >= (int) $schedule['capacity']) {
            return ['result' => 'full'];
        }

        // 既存チェック
        $existing = $this->getAttendance($scheduleId, $lineUserId);
        if ($existing) {
            return ['result' => 'already', 'status' => $existing['status']];
        }

        $attendanceId = $this->insertAttendance([
            'schedule_id' => $scheduleId,
            'user_id' => $userId,
            'line_user_id' => $lineUserId,
            'status' => 'pending',
        ]);

        ReservationEventLog::record($this->pdo, [
            'attendance_id' => $attendanceId,
            'schedule_id' => $scheduleId,
            'user_id' => $userId,
            'line_user_id' => $lineUserId,
            'event_type' => 'reserved',
            'payment_status' => 'unpaid',
            'amount' => (int)($schedule['fee'] ?? 0),
            'message' => 'Reservation applied from LINE/LIFF.',
        ]);

        return ['result' => 'applied'];
    }

    private function isUserBlocked(int $userId): bool {
        $stmt = $this->pdo->prepare("SELECT status FROM users WHERE id = ?" . $this->tenant->andWhere('users'));
        $stmt->execute(array_merge([$userId], $this->tenant->params('users')));
        $status = (string)($stmt->fetchColumn() ?: 'active');
        return in_array($status, ['suspended', 'banned'], true);
    }

    // 承認（管理者）
    public function approve(int $attendanceId, int $adminId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM class_attendances WHERE id = ?" . $this->tenant->andWhere('class_attendances'));
        $stmt->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));
        $att = $stmt->fetch();
        if (!$att) return null;

        $this->pdo->prepare("
            UPDATE class_attendances
            SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = NOW()
            WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
        ")->execute(array_merge([$adminId, $attendanceId], $this->tenant->params('class_attendances')));

        ReservationEventLog::record($this->pdo, [
            'attendance_id' => $attendanceId,
            'schedule_id' => $att['schedule_id'] ?? null,
            'user_id' => $att['user_id'] ?? null,
            'line_user_id' => $att['line_user_id'] ?? null,
            'event_type' => 'approved',
            'payment_status' => $att['payment_status'] ?? null,
            'amount' => (int)($att['payment_amount'] ?? 0),
            'message' => 'Reservation approved by admin.',
        ]);

        return $att;
    }

    // 受講生による予約キャンセル（今日以降・未参加の最も近い予約を取消）
    public function cancelUpcomingReservation(int $userId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT a.*, a.id AS attendance_id, s.id AS schedule_id, s.class_date, s.title
            FROM class_attendances a
            INNER JOIN class_schedules s ON s.id = a.schedule_id
            WHERE a.user_id = ?" . $this->tenant->andWhere('class_attendances', 'a') . "
              " . ($this->tenant->active() && $this->tenant->hasTenantColumn('class_schedules') ? 'AND s.tenant_id = ' . (int)$this->tenant->tenantId() : '') . "
              AND s.class_date >= CURDATE()
              AND a.attended_at IS NULL
              AND a.status IN ('pending','approved')
              AND s.status IN ('scheduled','active')
            ORDER BY s.class_date ASC
            LIMIT 1
        ");
        $stmt->execute(array_merge([$userId], $this->tenant->params('class_attendances')));
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['cancel_result'] = 'cancelled';
        $row['refund_result'] = 'none';
        $row['ticket_returned'] = false;

        if (($row['payment_status'] ?? '') === 'paid' && !empty($row['stripe_session_id'])) {
            require_once BASE_PATH . '/app/Services/StripeService.php';
            $stripe = new StripeService();
            if (!$stripe->refundBySession($row['stripe_session_id'])) {
                $row['cancel_result'] = 'refund_failed';
                ReservationEventLog::record($this->pdo, [
                    'attendance_id' => $row['attendance_id'],
                    'schedule_id' => $row['schedule_id'],
                    'user_id' => $row['user_id'],
                    'line_user_id' => $row['line_user_id'],
                    'event_type' => 'refund_failed',
                    'payment_status' => $row['payment_status'] ?? '',
                    'amount' => $row['payment_amount'] ?? 0,
                    'message' => 'Stripe refund failed during user cancellation.',
                ]);
                return $row;
            }
            require_once BASE_PATH . '/app/Services/PaymentLog.php';
            PaymentLog::markRefundedBySessionId($this->pdo, $row['stripe_session_id']);
            $row['refund_result'] = 'refunded';
            ReservationEventLog::record($this->pdo, [
                'attendance_id' => $row['attendance_id'],
                'schedule_id' => $row['schedule_id'],
                'user_id' => $row['user_id'],
                'line_user_id' => $row['line_user_id'],
                'event_type' => 'refunded',
                'payment_status' => 'refunded',
                'amount' => $row['payment_amount'] ?? 0,
                'message' => 'Stripe refund completed during user cancellation.',
            ]);
        }

        if (($row['payment_status'] ?? '') === 'ticket') {
            $this->pdo->prepare("
                UPDATE users
                SET ticket_balance = ticket_balance + 1, updated_at = NOW()
                WHERE id = ?" . $this->tenant->andWhere('users') . "
            ")->execute(array_merge([$userId], $this->tenant->params('users')));
            $row['ticket_returned'] = true;
            TicketLog::record($this->pdo, [
                'user_id' => $userId,
                'line_user_id' => $row['line_user_id'],
                'change_count' => 1,
                'reason' => 'return',
                'related_attendance_id' => $row['attendance_id'],
                'memo' => '予約キャンセルでチケットを返却',
            ]);
            ReservationEventLog::record($this->pdo, [
                'attendance_id' => $row['attendance_id'],
                'schedule_id' => $row['schedule_id'],
                'user_id' => $row['user_id'],
                'line_user_id' => $row['line_user_id'],
                'event_type' => 'ticket_returned',
                'payment_status' => 'ticket_refunded',
                'amount' => 0,
                'message' => 'Ticket returned during user cancellation.',
            ]);
        }

        $newPaymentStatus = $row['payment_status'] ?? '';
        if ($row['refund_result'] === 'refunded') {
            $newPaymentStatus = 'refunded';
        } elseif ($row['ticket_returned']) {
            $newPaymentStatus = 'ticket_refunded';
        }

        $this->pdo->prepare("
            UPDATE class_attendances
            SET status = 'cancelled',
                payment_status = ?,
                updated_at = NOW()
            WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
        ")->execute(array_merge([$newPaymentStatus, $row['attendance_id']], $this->tenant->params('class_attendances')));

        ReservationEventLog::record($this->pdo, [
            'attendance_id' => $row['attendance_id'],
            'schedule_id' => $row['schedule_id'],
            'user_id' => $row['user_id'],
            'line_user_id' => $row['line_user_id'],
            'event_type' => 'cancelled',
            'payment_status' => $newPaymentStatus,
            'amount' => $row['payment_amount'] ?? 0,
            'message' => 'Reservation cancelled by user.',
        ]);

        return $row;
    }

    // 当日参加チェックイン
    // 予約承認済み → attended に更新 / 未予約 → 飛び込み参加として登録し attended
    // 戻り値: ['result' => 'checked_in'|'already'|'walk_in', ...]
    public function checkInToday(int $scheduleId, int $userId, string $lineUserId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM class_attendances WHERE schedule_id = ? AND user_id = ?" . $this->tenant->andWhere('class_attendances'));
        $stmt->execute(array_merge([$scheduleId, $userId], $this->tenant->params('class_attendances')));
        $att = $stmt->fetch();

        if ($att) {
            // すでにチェックイン済み
            if (!empty($att['attended_at'])) {
                return ['result' => 'already'];
            }
            // 予約あり → 当日参加を記録（承認状態も approved に揃える）
            $this->pdo->prepare("
                UPDATE class_attendances
                SET attended_at = NOW(),
                    status = CASE WHEN status = 'rejected' THEN status ELSE 'approved' END,
                    approved_at = COALESCE(approved_at, NOW()),
                    updated_at = NOW()
                WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
            ")->execute(array_merge([$att['id']], $this->tenant->params('class_attendances')));
            ReservationEventLog::record($this->pdo, [
                'attendance_id' => $att['id'],
                'schedule_id' => $scheduleId,
                'user_id' => $userId,
                'line_user_id' => $lineUserId,
                'event_type' => 'attended',
                'payment_status' => $att['payment_status'] ?? null,
                'amount' => (int)($att['payment_amount'] ?? 0),
                'message' => 'Checked in from participation button.',
            ]);
            return ['result' => 'checked_in', 'had_reservation' => true];
        }

        // 未予約 → 飛び込み参加として登録し即チェックイン
        $attendanceId = $this->insertAttendance([
            'schedule_id' => $scheduleId,
            'user_id' => $userId,
            'line_user_id' => $lineUserId,
            'status' => 'approved',
            'approved_at' => date('Y-m-d H:i:s'),
            'attended_at' => date('Y-m-d H:i:s'),
        ]);
        ReservationEventLog::record($this->pdo, [
            'attendance_id' => $attendanceId,
            'schedule_id' => $scheduleId,
            'user_id' => $userId,
            'line_user_id' => $lineUserId,
            'event_type' => 'attended',
            'payment_status' => 'unpaid',
            'amount' => 0,
            'message' => 'Walk-in check-in from participation button.',
        ]);
        return ['result' => 'walk_in', 'had_reservation' => false];
    }

    // 自動承認用：schedule_id + user_id で承認（approved_by は NULL = システム）
    public function approveByScheduleUser(int $scheduleId, int $userId): void {
        $this->pdo->prepare("
            UPDATE class_attendances
            SET status = 'approved', approved_at = NOW(), notified_at = NOW(), updated_at = NOW()
            WHERE schedule_id = ? AND user_id = ? AND status = 'pending'" . $this->tenant->andWhere('class_attendances') . "
        ")->execute(array_merge([$scheduleId, $userId], $this->tenant->params('class_attendances')));

        $stmt = $this->pdo->prepare("SELECT * FROM class_attendances WHERE schedule_id = ? AND user_id = ?" . $this->tenant->andWhere('class_attendances') . " ORDER BY id DESC LIMIT 1");
        $stmt->execute(array_merge([$scheduleId, $userId], $this->tenant->params('class_attendances')));
        $att = $stmt->fetch();
        if ($att) {
            ReservationEventLog::record($this->pdo, [
                'attendance_id' => $att['id'],
                'schedule_id' => $scheduleId,
                'user_id' => $userId,
                'line_user_id' => $att['line_user_id'] ?? null,
                'event_type' => 'approved',
                'payment_status' => $att['payment_status'] ?? null,
                'amount' => (int)($att['payment_amount'] ?? 0),
                'message' => 'Reservation auto-approved by system.',
            ]);
        }
    }

    // 却下（管理者）
    public function reject(int $attendanceId, int $adminId, string $reason = ''): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM class_attendances WHERE id = ?" . $this->tenant->andWhere('class_attendances'));
        $stmt->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));
        $att = $stmt->fetch();
        if (!$att) return null;

        $this->pdo->prepare("
            UPDATE class_attendances
            SET status = 'rejected', approved_by = ?, rejected_reason = ?, updated_at = NOW()
            WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
        ")->execute(array_merge([$adminId, $reason, $attendanceId], $this->tenant->params('class_attendances')));

        return $att;
    }

    // ユーザーが今日承認済みかチェック
    public function isApprovedToday(string $lineUserId): bool {
        $schedule = $this->getTodaySchedule();
        if (!$schedule) return false;

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM class_attendances
            WHERE schedule_id = ? AND line_user_id = ? AND status = 'approved'" . $this->tenant->andWhere('class_attendances') . "
        ");
        $stmt->execute(array_merge([$schedule['id'], $lineUserId], $this->tenant->params('class_attendances')));
        return (int) $stmt->fetchColumn() > 0;
    }

    // 今日の承認済み参加者の生成件数上限を取得
    public function getTodayMaxRequests(string $lineUserId): int {
        $schedule = $this->getTodaySchedule();
        return $schedule ? (int) $schedule['max_requests'] : (int) Settings::maxDailyPerUser();
    }

    public function getScheduleById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM class_schedules WHERE id = ?" . $this->tenant->andWhere('class_schedules'));
        $stmt->execute(array_merge([$id], $this->tenant->params('class_schedules')));
        return $stmt->fetch() ?: null;
    }

    // LINE通知済みフラグを立てる
    public function markNotified(int $attendanceId): void {
        $this->pdo->prepare("UPDATE class_attendances SET notified_at = NOW(), updated_at = NOW() WHERE id = ?" . $this->tenant->andWhere('class_attendances'))
            ->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));
    }

    // 今日の申請一覧（管理画面用）
    public function getTodayAttendances(): array {
        $schedule = $this->getTodaySchedule();
        if (!$schedule) return [];

        $stmt = $this->pdo->prepare("
            SELECT a.*, u.display_name, u.picture_url,
                   (SELECT COUNT(*) FROM image_requests r
                    WHERE r.line_user_id = a.line_user_id" . $this->tenantJoinFilter('image_requests', 'r') . " AND DATE(r.created_at) = CURDATE()) AS today_requests
            FROM class_attendances a
            LEFT JOIN users u ON u.id = a.user_id" . $this->tenantJoinFilter('users', 'u') . "
            WHERE a.schedule_id = ?" . $this->tenant->andWhere('class_attendances', 'a') . "
            ORDER BY a.created_at ASC
        ");
        $stmt->execute(array_merge([$schedule['id']], $this->tenant->params('class_attendances')));
        return $stmt->fetchAll();
    }

    private function insertAttendance(array $data): int {
        $columns = array_keys($data);
        $values = array_values($data);
        [$columns, $values] = $this->tenant->assignInsert('class_attendances', $columns, $values);
        $columnSql = implode(', ', array_map(static fn($column) => '`' . $column . '`', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->pdo->prepare("INSERT INTO class_attendances ({$columnSql}, created_at, updated_at) VALUES ({$placeholders}, NOW(), NOW())");
        $stmt->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }
}
