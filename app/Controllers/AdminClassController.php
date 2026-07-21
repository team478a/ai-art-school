<?php
// app/Controllers/AdminClassController.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/AuditLog.php';
require_once BASE_PATH . '/app/Services/ClassScheduleService.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/Logger.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class AdminClassController {
    private PDO $pdo;
    private ClassScheduleService $svc;
    private LineService $line;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->ensureScheduleManagerColumns();
        $this->svc = new ClassScheduleService();
        $this->line = new LineService();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function index(): void {
        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.status IN ('pending','approved')" . $this->tenant->andWhere('class_attendances', 'a') . ") AS total_applicants,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.status = 'approved'" . $this->tenant->andWhere('class_attendances', 'a') . ") AS approved_count,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.status = 'pending'" . $this->tenant->andWhere('class_attendances', 'a') . ") AS pending_count,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.status IN ('cancelled','canceled')" . $this->tenant->andWhere('class_attendances', 'a') . ") AS cancelled_count,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.attended_at IS NOT NULL" . $this->tenant->andWhere('class_attendances', 'a') . ") AS attended_count,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.payment_status = 'unpaid' AND a.payment_amount > 0" . $this->tenant->andWhere('class_attendances', 'a') . ") AS unpaid_count,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.payment_status = 'paid'" . $this->tenant->andWhere('class_attendances', 'a') . ") AS paid_count,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.payment_status = 'refunded'" . $this->tenant->andWhere('class_attendances', 'a') . ") AS refunded_count,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.payment_status = 'ticket'" . $this->tenant->andWhere('class_attendances', 'a') . ") AS ticket_count,
                   (SELECT COUNT(*) FROM class_attendances a WHERE a.schedule_id = s.id AND a.payment_status = 'ticket_refunded'" . $this->tenant->andWhere('class_attendances', 'a') . ") AS ticket_refunded_count
            FROM class_schedules s
            WHERE COALESCE(s.status, '') <> 'deleted'" . $this->tenant->andWhere('class_schedules', 's') . "
            ORDER BY s.class_date DESC, s.start_time DESC
            LIMIT 30
        ");
        $params = array_merge(
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_schedules')
        );
        $stmt->execute($params);
        $schedules = $stmt->fetchAll();

        $today = $this->svc->getTodaySchedule();
        if ($today && !$this->belongsToTenant('class_schedules', $today)) {
            $today = null;
        }
        if ($today && (($today['status'] ?? '') === 'deleted')) {
            $today = null;
        }
        $attendances = $today ? array_values(array_filter(
            $this->svc->getTodayAttendances(),
            fn($att) => ($att['status'] ?? '') !== 'deleted'
        )) : [];

        require BASE_PATH . '/app/Views/admin/class_schedules.php';
    }

    public function create(): void {
        require BASE_PATH . '/app/Views/admin/class_schedule_form.php';
    }

    public function store(): void {
        $this->handleListAction();
        $this->validateScheduleInput();
        $reminderAt = !empty($_POST['reminder_at']) ? date('Y-m-d H:i:s', strtotime($_POST['reminder_at'])) : null;

        $columns = [
            'title', 'class_date', 'start_time', 'end_time', 'checkin_open', 'checkin_close',
            'capacity', 'max_requests', 'description', 'organizer', 'public_message',
            'event_format', 'location', 'zoom_url', 'auto_approve', 'created_by_admin_id',
            'created_by_admin_name', 'fee', 'reminder_at', 'reminder_message',
        ];
        $values = [
            trim($_POST['title'] ?? 'AIアート教室'),
            $_POST['class_date'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['checkin_open'],
            $_POST['checkin_close'],
            (int)($_POST['capacity'] ?? 20),
            (int)($_POST['max_requests'] ?? 2),
            trim($_POST['description'] ?? ''),
            trim($_POST['organizer'] ?? ''),
            trim($_POST['public_message'] ?? ''),
            in_array($_POST['event_format'] ?? 'realtime', ['realtime','zoom','hybrid'], true) ? $_POST['event_format'] : 'realtime',
            trim($_POST['location'] ?? ''),
            trim($_POST['zoom_url'] ?? ''),
            !empty($_POST['auto_approve']) ? 1 : 0,
            (int)($_SESSION['admin_id'] ?? 0) ?: null,
            $this->currentAdminName(),
            (int)($_POST['fee'] ?? 0),
            $reminderAt,
            trim($_POST['reminder_message'] ?? ''),
        ];
        [$columns, $values] = $this->tenant->assignInsert('class_schedules', $columns, $values);

        $columnSql = implode(', ', array_map(
            static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
            $columns
        ));
        $placeholderSql = implode(', ', array_fill(0, count($values), '?'));
        $this->pdo->prepare("
            INSERT INTO class_schedules ({$columnSql}, status, created_at, updated_at)
            VALUES ({$placeholderSql}, 'scheduled', NOW(), NOW())
        ")->execute($values);

        AuditLog::record('class_create', trim($_POST['title'] ?? ''), $_POST['class_date'] ?? '');
        header('Location: /admin/classes?created=1');
        exit;
    }

    public function edit(int $id): void {
        $schedule = $this->svc->getScheduleById($id);
        if (!$schedule || !$this->belongsToTenant('class_schedules', $schedule)) {
            http_response_code(404);
            return;
        }
        require BASE_PATH . '/app/Views/admin/class_schedule_form.php';
    }

    public function update(int $id): void {
        $this->validateScheduleInput();
        $reminderAt = !empty($_POST['reminder_at']) ? date('Y-m-d H:i:s', strtotime($_POST['reminder_at'])) : null;

        $this->pdo->prepare("
            UPDATE class_schedules
            SET title=?, class_date=?, start_time=?, end_time=?, checkin_open=?, checkin_close=?,
                capacity=?, max_requests=?, description=?, organizer=?, public_message=?,
                event_format=?, location=?, zoom_url=?, auto_approve=?, fee=?, reminder_at=?, reminder_message=?,
                reminder_sent_at = CASE WHEN reminder_at <=> ? THEN reminder_sent_at ELSE NULL END,
                updated_at=NOW()
            WHERE id=?" . $this->tenant->andWhere('class_schedules') . "
        ")->execute([
            trim($_POST['title'] ?? 'AIアート教室'),
            $_POST['class_date'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['checkin_open'],
            $_POST['checkin_close'],
            (int)($_POST['capacity'] ?? 20),
            (int)($_POST['max_requests'] ?? 2),
            trim($_POST['description'] ?? ''),
            trim($_POST['organizer'] ?? ''),
            trim($_POST['public_message'] ?? ''),
            in_array($_POST['event_format'] ?? 'realtime', ['realtime','zoom','hybrid'], true) ? $_POST['event_format'] : 'realtime',
            trim($_POST['location'] ?? ''),
            trim($_POST['zoom_url'] ?? ''),
            !empty($_POST['auto_approve']) ? 1 : 0,
            (int)($_POST['fee'] ?? 0),
            $reminderAt,
            trim($_POST['reminder_message'] ?? ''),
            $reminderAt,
            $id,
            ...$this->tenant->params('class_schedules'),
        ]);

        AuditLog::record('class_update', trim($_POST['title'] ?? ''), 'id=' . $id);
        header('Location: /admin/classes?updated=1');
        exit;
    }

    public function markPaid(int $attendanceId): void {
        require_once BASE_PATH . '/app/Services/BillingService.php';
        (new BillingService())->markPaid($attendanceId);
        AuditLog::record('payment_collect', 'attendance_id=' . $attendanceId, '');
        header('Location: /admin/classes');
        exit;
    }

    public function sendReminder(int $id): void {
        require_once BASE_PATH . '/app/Services/ReminderService.php';
        $sent = (new ReminderService())->sendNow($id);
        header('Location: /admin/classes?reminded=' . $sent);
        exit;
    }

    public function cancel(int $id): void {
        $stmt = $this->pdo->prepare("SELECT * FROM class_schedules WHERE id = ?" . $this->tenant->andWhere('class_schedules'));
        $stmt->execute(array_merge([$id], $this->tenant->params('class_schedules')));
        $schedule = $stmt->fetch();

        if (!$schedule) {
            header('Location: /admin/classes?delete_error=not_found');
            exit;
        }

        $this->pdo->prepare("UPDATE class_schedules SET status='canceled', updated_at=NOW() WHERE id=?" . $this->tenant->andWhere('class_schedules'))
            ->execute(array_merge([$id], $this->tenant->params('class_schedules')));

        $notified = 0;
        if ($schedule) {
            $users = $this->pdo->prepare("
                SELECT u.line_user_id
                FROM class_attendances a
                INNER JOIN users u ON u.id = a.user_id" . $this->tenantJoinFilter('users', 'u') . "
                WHERE a.schedule_id = ? AND a.status IN ('pending','approved') AND u.status = 'active'"
                . $this->tenant->andWhere('class_attendances', 'a')
                . $this->tenant->andWhere('users', 'u') . "
            ");
            $users->execute(array_merge([$id], $this->tenant->params('class_attendances'), $this->tenant->params('users')));
            $date = date('n月j日', strtotime((string)$schedule['class_date']));
            foreach ($users->fetchAll() as $u) {
                $sent = $this->line->pushText(
                    $u['line_user_id'],
                    "【教室中止のお知らせ】\n\n{$date}「{$schedule['title']}」は中止となりました。\nご予約いただいたのに申し訳ありません。\n\nまたの機会をお待ちしております。"
                );
                if ($sent) {
                    $notified++;
                }
                usleep(150000);
            }
        }

        AuditLog::record('class_cancel', $schedule['title'] ?? ('id=' . $id), "{$notified}人に通知");
        header('Location: /admin/classes?canceled=' . $notified);
        exit;
    }

    public function approve(int $attendanceId): void {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $att = $this->svc->approve($attendanceId, $adminId);
        if (!$att) {
            http_response_code(404);
            return;
        }

        $schedule = $this->svc->getScheduleById((int)$att['schedule_id']);
        $maxReq = $schedule ? (int)$schedule['max_requests'] : 2;

        $sent = $this->line->pushText($att['line_user_id'], $this->buildApprovalNotice($schedule, $maxReq));
        if ($sent) {
            $this->svc->markNotified($attendanceId);
        }

        AuditLog::record('attendance_approve', $att['line_user_id'] ?? '', 'attendance_id=' . $attendanceId);
        Logger::info('class', "attendance approved: attendance_id={$attendanceId}");

        if ($this->isAjax()) {
            echo json_encode(['ok' => true]);
            return;
        }
        header('Location: /admin/classes');
        exit;
    }

    public function reject(int $attendanceId): void {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $att = $this->svc->reject($attendanceId, $adminId, $reason);
        if (!$att) {
            http_response_code(404);
            return;
        }

        $msg = "今回の参加申請はお受けできませんでした。";
        if ($reason !== '') {
            $msg .= "\n理由: {$reason}";
        }
        $this->line->pushText($att['line_user_id'], $msg);
        Logger::info('class', "attendance rejected: attendance_id={$attendanceId}");

        if ($this->isAjax()) {
            echo json_encode(['ok' => true]);
            return;
        }
        header('Location: /admin/classes');
        exit;
    }

    public function approveAll(int $scheduleId): void {
        $stmt = $this->pdo->prepare("SELECT id FROM class_attendances WHERE schedule_id = ? AND status = 'pending'" . $this->tenant->andWhere('class_attendances'));
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_attendances')));
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            $this->svc->approve((int)$id, (int)($_SESSION['admin_id'] ?? 0));
            usleep(200000);
        }

        $this->notifyApprovedAttendances($scheduleId, array_map('intval', $ids));
        AuditLog::record('attendance_approve_all', 'schedule_id=' . $scheduleId, '');
        header('Location: /admin/classes?approved_all=1');
        exit;
    }

    public function approveSelected(int $scheduleId): void {
        $selected = $_POST['attendance_ids'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $selected))));
        if (!$ids) {
            header('Location: /admin/classes/' . $scheduleId . '?approve_error=empty');
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM class_attendances
            WHERE schedule_id = ?
              AND status = 'pending'
              AND id IN ({$placeholders})" . $this->tenant->andWhere('class_attendances') . "
        ");
        $stmt->execute(array_merge([$scheduleId], $ids, $this->tenant->params('class_attendances')));
        $targetIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (!$targetIds) {
            header('Location: /admin/classes/' . $scheduleId . '?approve_error=no_target');
            exit;
        }

        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        foreach ($targetIds as $id) {
            $this->svc->approve($id, $adminId);
            usleep(200000);
        }

        $this->notifyApprovedAttendances($scheduleId, $targetIds);
        AuditLog::record('attendance_approve_selected', 'schedule_id=' . $scheduleId, 'count=' . count($targetIds));
        header('Location: /admin/classes?approved_all=1');
        exit;
    }

    public function deleteAttendance(int $attendanceId): void {
        $stmt = $this->pdo->prepare("
            SELECT a.*, s.title, s.class_date
            FROM class_attendances a
            LEFT JOIN class_schedules s ON s.id = a.schedule_id" . $this->tenantJoinFilter('class_schedules', 's') . "
            WHERE a.id = ?" . $this->tenant->andWhere('class_attendances', 'a') . "
            LIMIT 1
        ");
        $stmt->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));
        $attendance = $stmt->fetch();
        if (!$attendance) {
            header('Location: /admin/classes?delete_error=not_found');
            exit;
        }

        if ($this->attendanceHasLockedPayment($attendance)) {
            header('Location: /admin/classes?delete_error=paid_attendance');
            exit;
        }

        $this->pdo->beginTransaction();
        try {
            $this->deleteKnownRelations([$attendanceId], [(int)$attendance['schedule_id']]);
            $this->pdo->prepare("DELETE FROM class_attendances WHERE id = ?" . $this->tenant->andWhere('class_attendances'))
                ->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            Logger::error('class', 'attendance hard delete failed, fallback soft delete: ' . $e->getMessage());
            if (!$this->softDeleteAttendance($attendanceId)) {
                header('Location: /admin/classes?delete_error=failed');
                exit;
            }
        }

        AuditLog::record('attendance_delete', 'attendance_id=' . $attendanceId, ($attendance['title'] ?? '') . ' ' . ($attendance['line_user_id'] ?? ''));
        header('Location: /admin/classes?attendance_deleted=1');
        exit;
    }

    public function deleteSchedule(int $id): void {
        $schedule = $this->svc->getScheduleById($id);
        if (!$schedule || !$this->belongsToTenant('class_schedules', $schedule)) {
            header('Location: /admin/classes?delete_error=not_found');
            exit;
        }

        if ($this->scheduleHasLockedPayment($id)) {
            header('Location: /admin/classes?delete_error=paid_schedule');
            exit;
        }

        $attendanceIds = $this->attendanceIdsForSchedule($id);
        $this->pdo->beginTransaction();
        try {
            $this->deleteKnownRelations($attendanceIds, [$id]);
            $this->pdo->prepare("DELETE FROM class_attendances WHERE schedule_id = ?" . $this->tenant->andWhere('class_attendances'))
                ->execute(array_merge([$id], $this->tenant->params('class_attendances')));
            $this->pdo->prepare("DELETE FROM class_schedules WHERE id = ?" . $this->tenant->andWhere('class_schedules'))
                ->execute(array_merge([$id], $this->tenant->params('class_schedules')));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            Logger::error('class', 'schedule hard delete failed, fallback soft delete: ' . $e->getMessage());
            if (!$this->softDeleteSchedule($id)) {
                header('Location: /admin/classes?delete_error=failed');
                exit;
            }
        }

        AuditLog::record('class_delete', $schedule['title'] ?? ('id=' . $id), (string)($schedule['class_date'] ?? ''));
        header('Location: /admin/classes?schedule_deleted=1');
        exit;
    }

    private function notifyApprovedAttendances(int $scheduleId, array $attendanceIds): void {
        if (!$attendanceIds) {
            return;
        }
        $schedule = $this->svc->getScheduleById($scheduleId);
        $maxReq = $schedule ? (int)$schedule['max_requests'] : 2;
        $placeholders = implode(',', array_fill(0, count($attendanceIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM class_attendances
            WHERE schedule_id = ?
              AND status = 'approved'
              AND notified_at IS NULL
              AND id IN ({$placeholders})" . $this->tenant->andWhere('class_attendances') . "
        ");
        $stmt->execute(array_merge([$scheduleId], $attendanceIds, $this->tenant->params('class_attendances')));
        foreach ($stmt->fetchAll() as $att) {
            $this->line->pushText($att['line_user_id'], $this->buildApprovalNotice($schedule, $maxReq));
            $this->svc->markNotified((int)$att['id']);
            usleep(200000);
        }
    }

    private function buildApprovalNotice(?array $schedule, int $maxReq): string {
        $dateStr = $schedule ? date('n月j日', strtotime((string)$schedule['class_date'])) : '開催日';
        $title = $schedule ? (string)($schedule['title'] ?? 'AIアート教室') : 'AIアート教室';
        $start = $schedule && !empty($schedule['start_time']) ? substr((string)$schedule['start_time'], 0, 5) : '';
        $timeLine = $start !== '' ? "{$dateStr} {$start}〜" : $dateStr;

        return "✅ 予約が承認されました。\n\n"
            . "{$title}\n"
            . "{$timeLine}\n\n"
            . "当日のZoom URLまたは会場の場所は、開催当日にLINEでご案内します。\n"
            . "複数の教室を予約している場合も、当日の対象教室だけを案内します。\n\n"
            . "当日は{$maxReq}件まで画像生成できます。\n"
            . "参加時は案内に従って出席確認をしてください。";
    }

    private function handleListAction(): void {
        $action = (string)($_POST['class_action'] ?? '');
        if ($action === 'delete_attendance') {
            $this->deleteAttendance((int)($_POST['attendance_id'] ?? 0));
        }
        if ($action === 'delete_schedule') {
            $this->deleteSchedule((int)($_POST['schedule_id'] ?? 0));
        }
    }

    private function ensureScheduleManagerColumns(): void {
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM class_schedules")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('created_by_admin_id', $cols, true)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN created_by_admin_id INT NULL AFTER auto_approve");
            }
            if (!in_array('created_by_admin_name', $cols, true)) {
                $this->pdo->exec("ALTER TABLE class_schedules ADD COLUMN created_by_admin_name VARCHAR(255) NULL AFTER created_by_admin_id");
            }
        } catch (Throwable $e) {
            Logger::error('class', 'schedule manager columns ensure failed: ' . $e->getMessage());
        }
    }

    private function currentAdminName(): string {
        $name = trim((string)($_SESSION['admin_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $email = trim((string)($_SESSION['admin_email'] ?? ''));
        return $email !== '' ? $email : '管理者';
    }

    private function validateScheduleInput(): void {
        if (empty($_POST['class_date'])) {
            die('開催日は必須です。');
        }
        if (empty($_POST['start_time']) || empty($_POST['end_time'])) {
            die('開始時刻と終了時刻は必須です。');
        }
    }

    private function attendanceHasLockedPayment(array $attendance): bool {
        $paymentStatus = (string)($attendance['payment_status'] ?? '');
        if (in_array($paymentStatus, ['paid', 'ticket'], true)) {
            return true;
        }
        $stripeSessionId = trim((string)($attendance['stripe_session_id'] ?? ''));
        return $stripeSessionId !== '' && !in_array($paymentStatus, ['refunded', 'ticket_refunded'], true);
    }

    private function scheduleHasLockedPayment(int $scheduleId): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM class_attendances
            WHERE schedule_id = ?
              AND (
                payment_status IN ('paid','ticket')
                OR (COALESCE(stripe_session_id, '') <> '' AND payment_status NOT IN ('refunded','ticket_refunded'))
              )" . $this->tenant->andWhere('class_attendances') . "
        ");
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_attendances')));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function attendanceIdsForSchedule(int $scheduleId): array {
        $stmt = $this->pdo->prepare("SELECT id FROM class_attendances WHERE schedule_id = ?" . $this->tenant->andWhere('class_attendances'));
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_attendances')));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function deleteKnownRelations(array $attendanceIds, array $scheduleIds): void {
        $this->deleteReservationEvents($attendanceIds, $scheduleIds);
        $this->deleteTicketLogs($attendanceIds);
        $this->deleteRelatedRowsIfPresent('job_queue', 'attendance_id', $attendanceIds);
        $this->deleteRelatedRowsIfPresent('job_queue', 'schedule_id', $scheduleIds);
        $this->deleteRelatedRowsIfPresent('image_requests', 'attendance_id', $attendanceIds);
        $this->deleteRelatedRowsIfPresent('image_requests', 'schedule_id', $scheduleIds);
        $this->deleteRelatedRowsIfPresent('generated_images', 'attendance_id', $attendanceIds);
        $this->deleteRelatedRowsIfPresent('generated_images', 'schedule_id', $scheduleIds);
    }

    private function deleteReservationEvents(array $attendanceIds, array $scheduleIds): void {
        if (!$this->tableExists('reservation_event_logs')) {
            return;
        }
        if ($attendanceIds) {
            $this->deleteByIds('reservation_event_logs', 'attendance_id', $attendanceIds);
        }
        if ($scheduleIds) {
            $this->deleteByIds('reservation_event_logs', 'schedule_id', $scheduleIds);
        }
    }

    private function deleteTicketLogs(array $attendanceIds): void {
        if (!$attendanceIds || !$this->tableExists('ticket_logs') || !$this->columnExists('ticket_logs', 'related_attendance_id')) {
            return;
        }
        $this->deleteByIds('ticket_logs', 'related_attendance_id', $attendanceIds);
    }

    private function deleteRelatedRowsIfPresent(string $table, string $column, array $ids): void {
        if (!$ids || !$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return;
        }
        $this->deleteByIds($table, $column, $ids);
    }

    private function softDeleteAttendance(int $attendanceId): bool {
        try {
            $stmt = $this->pdo->prepare("UPDATE class_attendances SET status = 'deleted', updated_at = NOW() WHERE id = ?" . $this->tenant->andWhere('class_attendances'));
            $stmt->execute(array_merge([$attendanceId], $this->tenant->params('class_attendances')));
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            Logger::error('class', 'attendance soft delete failed: ' . $e->getMessage());
            return false;
        }
    }

    private function softDeleteSchedule(int $scheduleId): bool {
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare("UPDATE class_attendances SET status = 'deleted', updated_at = NOW() WHERE schedule_id = ?" . $this->tenant->andWhere('class_attendances'))
                ->execute(array_merge([$scheduleId], $this->tenant->params('class_attendances')));
            $stmt = $this->pdo->prepare("UPDATE class_schedules SET status = 'deleted', updated_at = NOW() WHERE id = ?" . $this->tenant->andWhere('class_schedules'));
            $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_schedules')));
            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error('class', 'schedule soft delete failed: ' . $e->getMessage());
            return false;
        }
    }

    private function deleteByIds(string $table, string $column, array $ids): void {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $tenantWhere = $this->tenant->andWhere($table);
        $stmt = $this->pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders}){$tenantWhere}");
        $stmt->execute(array_merge($ids, $this->tenant->params($table)));
    }

    private function belongsToTenant(string $table, array $row): bool {
        if (!$this->tenant->active()) {
            return true;
        }
        if (!$this->tenant->hasTenantColumn($table)) {
            return $this->tenant->isDefaultTenant();
        }
        return isset($row['tenant_id']) && (int)$row['tenant_id'] === (int)$this->tenant->tenantId();
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }

    private function tableExists(string $table): bool {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    }

    private function isAjax(): bool {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }
}
