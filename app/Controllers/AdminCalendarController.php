<?php
// app/Controllers/AdminCalendarController.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class AdminCalendarController {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function show(): void {
        $year = (int)($_GET['y'] ?? date('Y'));
        $month = (int)($_GET['m'] ?? date('n'));
        if ($month < 1) {
            $month = 12;
            $year--;
        }
        if ($month > 12) {
            $month = 1;
            $year++;
        }

        $selectedDate = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            $selectedDate = date('Y-m-d');
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        $attendanceScope = $this->tenant->andWhere('class_attendances', 'a');
        $scheduleScope = $this->tenant->andWhere('class_schedules', 's');

        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM class_attendances a
                    WHERE a.schedule_id = s.id
                      AND a.status IN ('pending','approved'){$attendanceScope}) AS total_applicants,
                   (SELECT COUNT(*) FROM class_attendances a
                    WHERE a.schedule_id = s.id
                      AND a.status = 'approved'{$attendanceScope}) AS approved_count,
                   (SELECT COUNT(*) FROM class_attendances a
                    WHERE a.schedule_id = s.id
                      AND a.status = 'pending'{$attendanceScope}) AS pending_count,
                   (SELECT COUNT(*) FROM class_attendances a
                    WHERE a.schedule_id = s.id
                      AND a.status IN ('cancelled','canceled'){$attendanceScope}) AS cancelled_count,
                   (SELECT COUNT(*) FROM class_attendances a
                    WHERE a.schedule_id = s.id
                      AND a.attended_at IS NOT NULL{$attendanceScope}) AS attended_count
            FROM class_schedules s
            WHERE s.class_date BETWEEN ? AND ?
              AND COALESCE(s.status, '') <> 'deleted'{$scheduleScope}
            ORDER BY s.class_date ASC, s.start_time ASC
        ");
        $stmt->execute(array_merge(
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            $this->tenant->params('class_attendances'),
            [$start, $end],
            $this->tenant->params('class_schedules')
        ));
        $rows = $stmt->fetchAll();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['class_date']][] = $row;
        }
        $selectedEvents = $byDate[$selectedDate] ?? [];

        require BASE_PATH . '/app/Views/admin/calendar.php';
    }
}
