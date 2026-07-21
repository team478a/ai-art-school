<?php
// app/Controllers/AdminCancellationController.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/ReservationEventLog.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class AdminCancellationController {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function index(): void {
        ReservationEventLog::ensureTable($this->pdo);

        $eventType = $_GET['event'] ?? '';
        if (!in_array($eventType, ['', 'cancelled', 'refund_failed', 'refunded', 'ticket_returned'], true)) {
            $eventType = '';
        }

        $events = ReservationEventLog::recent($this->pdo, 200, $eventType);
        $cancelledAttendances = $this->cancelledAttendances();
        $summary = $this->summary();

        require BASE_PATH . '/app/Views/admin/cancellations.php';
    }

    private function cancelledAttendances(): array {
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.display_name, s.title, s.class_date, s.start_time
            FROM class_attendances a
            LEFT JOIN users u ON u.id = a.user_id" . $this->tenantJoinFilter('users', 'u') . "
            LEFT JOIN class_schedules s ON s.id = a.schedule_id" . $this->tenantJoinFilter('class_schedules', 's') . "
            WHERE (a.status IN ('cancelled', 'canceled')
               OR a.payment_status IN ('refunded', 'ticket_refunded'))" . $this->tenant->andWhere('class_attendances', 'a') . "
            ORDER BY a.updated_at DESC, a.id DESC
            LIMIT 200
        ");
        $stmt->execute($this->tenant->params('class_attendances'));
        return $stmt->fetchAll();
    }

    private function summary(): array {
        $stmtSummary = $this->pdo->prepare("
            SELECT
                COUNT(CASE WHEN status IN ('cancelled','canceled') THEN 1 END) AS cancelled_count,
                COUNT(CASE WHEN payment_status = 'refunded' THEN 1 END) AS refunded_count,
                COUNT(CASE WHEN payment_status = 'ticket_refunded' THEN 1 END) AS ticket_returned_count
            FROM class_attendances" . ($this->tenant->where('class_attendances') !== '' ? ' WHERE ' . $this->tenant->where('class_attendances') : ''));
        $stmtSummary->execute($this->tenant->params('class_attendances'));
        $row = $stmtSummary->fetch();

        $stmtFailed = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservation_event_logs WHERE event_type = 'refund_failed'" . $this->tenant->andWhere('reservation_event_logs'));
        $stmtFailed->execute($this->tenant->params('reservation_event_logs'));
        $failed = $stmtFailed->fetchColumn();

        $row = $row ?: [];
        $row['refund_failed_count'] = (int)$failed;
        return $row;
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }
}
