<?php
// app/Services/ReminderService.php
// Sends day-of class access information to approved participants.

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/Logger.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class ReminderService {
    private PDO $pdo;
    private LineService $line;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->line = new LineService();
        $this->tenant = new TenantScopeService($this->pdo);
    }

    public function dispatchDue(): int {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM class_schedules
            WHERE reminder_at IS NOT NULL
              AND reminder_at <= NOW()
              AND reminder_sent_at IS NULL
              AND status IN ('scheduled', 'active')" . $this->tenant->andWhere('class_schedules') . "
            ORDER BY reminder_at ASC
            LIMIT 5
        ");
        $stmt->execute($this->tenant->params('class_schedules'));
        $schedules = $stmt->fetchAll();
        if (!$schedules) {
            return 0;
        }

        $sentTotal = 0;
        foreach ($schedules as $schedule) {
            $sentTotal += $this->sendForSchedule($schedule);
            $this->pdo->prepare("UPDATE class_schedules SET reminder_sent_at = NOW() WHERE id = ?" . $this->tenant->andWhere('class_schedules'))
                ->execute(array_merge([(int)$schedule['id']], $this->tenant->params('class_schedules')));
        }

        return $sentTotal;
    }

    public function sendNow(int $scheduleId): int {
        $stmt = $this->pdo->prepare("SELECT * FROM class_schedules WHERE id = ?" . $this->tenant->andWhere('class_schedules'));
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_schedules')));
        $schedule = $stmt->fetch();
        if (!$schedule) {
            return 0;
        }

        $sent = $this->sendForSchedule($schedule);
        $this->pdo->prepare("UPDATE class_schedules SET reminder_sent_at = NOW() WHERE id = ?" . $this->tenant->andWhere('class_schedules'))
            ->execute(array_merge([$scheduleId], $this->tenant->params('class_schedules')));

        return $sent;
    }

    private function sendForSchedule(array $schedule): int {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT u.line_user_id
            FROM class_attendances a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.schedule_id = ?
              AND a.status = 'approved'
              AND u.status = 'active'
              AND u.line_user_id IS NOT NULL
              AND u.line_user_id <> ''
              " . $this->tenant->andWhere('class_attendances', 'a')
                . $this->tenant->andWhere('users', 'u') . "
        ");
        $stmt->execute(array_merge([(int)$schedule['id']], $this->tenant->params('class_attendances'), $this->tenant->params('users')));
        $users = $stmt->fetchAll();
        if (!$users) {
            return 0;
        }

        $message = $this->buildMessage($schedule);
        $sent = 0;

        foreach ($users as $user) {
            if ($this->line->pushText($user['line_user_id'], $message)) {
                $sent++;
            }
            usleep(100000);
        }

        Logger::info('reminder', "day_of_access_notice schedule={$schedule['id']} sent={$sent}");
        return $sent;
    }

    private function buildMessage(array $schedule): string {
        $custom = trim((string)($schedule['reminder_message'] ?? ''));
        $date = $this->formatDate((string)$schedule['class_date']);
        $start = substr((string)($schedule['start_time'] ?? ''), 0, 5);
        $end = substr((string)($schedule['end_time'] ?? ''), 0, 5);
        $time = $start;
        if ($end !== '') {
            $time .= '-' . $end;
        }

        $lines = [];
        $lines[] = "本日の教室のご案内です。";
        $lines[] = "";
        $lines[] = (string)($schedule['title'] ?? 'AIアート教室');
        $lines[] = trim($date . ' ' . $time);

        if (!empty($schedule['organizer'])) {
            $lines[] = "主催者：" . $schedule['organizer'];
        }

        $access = $this->buildAccessInfo($schedule);
        if ($access !== '') {
            $lines[] = "";
            $lines[] = $access;
        } else {
            $lines[] = "";
            $lines[] = "参加方法の詳細は、教室運営からの案内をご確認ください。";
        }

        if (!empty($schedule['public_message'])) {
            $lines[] = "";
            $lines[] = trim((string)$schedule['public_message']);
        }

        if ($custom !== '') {
            $lines[] = "";
            $lines[] = "補足：";
            $lines[] = $custom;
        }

        $lines[] = "";
        $lines[] = "複数の教室を予約している場合も、この案内は本日の対象教室のみです。";
        $lines[] = "参加時はLINEの「参加」ボタンから出席確認をしてください。";

        return implode("\n", $lines);
    }

    private function buildAccessInfo(array $schedule): string {
        $format = (string)($schedule['event_format'] ?? 'realtime');
        $parts = [];

        if (($format === 'zoom' || $format === 'hybrid') && !empty($schedule['zoom_url'])) {
            $parts[] = "Zoom参加URL\n" . trim((string)$schedule['zoom_url']);
        }

        if (($format === 'realtime' || $format === 'hybrid') && !empty($schedule['location'])) {
            $parts[] = "会場\n" . trim((string)$schedule['location']);
        }

        return implode("\n\n", $parts);
    }

    private function formatDate(string $date): string {
        $ts = strtotime($date);
        if (!$ts) {
            return $date;
        }
        return date('Y年n月j日', $ts);
    }
}
