<?php
// app/Controllers/AdminReservationController.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/ReservationEventLog.php';
require_once BASE_PATH . '/app/Services/AuditLog.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class AdminReservationController {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function index(): void {
        ReservationEventLog::ensureTable($this->pdo);

        $eventType = $_GET['event'] ?? '';
        $allowed = ['', 'reserved', 'approved', 'attended', 'paid', 'cancelled', 'refunded', 'refund_failed', 'ticket_returned'];
        if (!in_array($eventType, $allowed, true)) {
            $eventType = '';
        }

        $events = ReservationEventLog::recent($this->pdo, 300, $eventType);
        $summary = $this->summary();

        require BASE_PATH . '/app/Views/admin/reservations.php';
    }

    public function delete(): void {
        ReservationEventLog::ensureTable($this->pdo);

        $eventId = (int)($_POST['event_id'] ?? 0);
        $eventType = trim((string)($_POST['event_filter'] ?? ''));
        $redirect = '/admin/reservations?deleted=1';
        if ($eventType !== '') {
            $redirect .= '&event=' . rawurlencode($eventType);
        }

        if ($eventId <= 0) {
            header('Location: /admin/reservations?delete_error=not_found');
            exit;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM reservation_event_logs WHERE id = ?" . $this->tenant->andWhere('reservation_event_logs') . " LIMIT 1");
        $stmt->execute(array_merge([$eventId], $this->tenant->params('reservation_event_logs')));
        $event = $stmt->fetch();
        if (!$event) {
            header('Location: /admin/reservations?delete_error=not_found');
            exit;
        }

        try {
            $del = $this->pdo->prepare("DELETE FROM reservation_event_logs WHERE id = ?" . $this->tenant->andWhere('reservation_event_logs'));
            $del->execute(array_merge([$eventId], $this->tenant->params('reservation_event_logs')));
            AuditLog::record('reservation_log_delete', 'event_id=' . $eventId, ($event['event_type'] ?? '') . ' attendance_id=' . ($event['attendance_id'] ?? ''));
        } catch (Throwable $e) {
            header('Location: /admin/reservations?delete_error=failed');
            exit;
        }

        header('Location: ' . $redirect);
        exit;
    }

    private function summary(): array {
        ReservationEventLog::ensureTable($this->pdo);

        $eventWhere = $this->tenant->where('reservation_event_logs');
        $stmtEvents = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total_events,
                COUNT(CASE WHEN event_type IN ('reserved','approved','attended') THEN 1 END) AS reservation_events,
                COUNT(CASE WHEN event_type IN ('paid','refunded','refund_failed') THEN 1 END) AS payment_events,
                COUNT(CASE WHEN event_type IN ('cancelled','ticket_returned') THEN 1 END) AS cancel_events
            FROM reservation_event_logs" . ($eventWhere !== '' ? ' WHERE ' . $eventWhere : ''));
        $stmtEvents->execute($this->tenant->params('reservation_event_logs'));
        $row = $stmtEvents->fetch();

        $stmtActive = $this->pdo->prepare("
            SELECT COUNT(*) FROM class_attendances
            WHERE status IN ('pending','approved')" . $this->tenant->andWhere('class_attendances'));
        $stmtActive->execute($this->tenant->params('class_attendances'));
        $active = $stmtActive->fetchColumn();

        $row = $row ?: [];
        $row['active_reservations'] = (int)$active;
        return $row;
    }
}
