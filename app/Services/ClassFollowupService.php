<?php
// app/Services/ClassFollowupService.php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/Logger.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class ClassFollowupService {
    private PDO $pdo;
    private LineService $line;
    private TenantScopeService $tenant;

    public function __construct(?PDO $pdo = null, ?LineService $line = null) {
        $this->pdo = $pdo ?: get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->line = $line ?: new LineService();
        $this->ensureTable();
    }

    public function dispatchDue(int $limit = 30): int {
        $limit = max(1, min(100, $limit));
        $sent = 0;

        foreach (['before_day', 'same_day', 'after_class'] as $type) {
            if ($sent >= $limit) {
                break;
            }
            $targets = $this->fetchTargets($type, $limit - $sent);
            foreach ($targets as $target) {
                $lineUserId = trim((string)($target['line_user_id'] ?? ''));
                if ($lineUserId === '') {
                    continue;
                }

                $message = $this->buildMessage($type, $target);
                if ($this->line->pushText($lineUserId, $message)) {
                    $this->markSent($type, $target);
                    $sent++;
                    usleep(150000);
                }
            }
        }

        if ($sent > 0) {
            Logger::info('class', "class notifications sent={$sent}");
        }

        return $sent;
    }

    private function fetchTargets(string $type, int $limit): array {
        $where = $this->whereForType($type);
        if ($where === '') {
            return [];
        }

        $sql = "
            SELECT
                a.id AS attendance_id,
                a.schedule_id,
                a.user_id,
                COALESCE(u.line_user_id, a.line_user_id) AS line_user_id,
                u.display_name,
                s.title,
                s.class_date,
                s.start_time,
                s.end_time,
                s.location,
                s.zoom_url
            FROM class_attendances a
            INNER JOIN class_schedules s ON s.id = a.schedule_id" . $this->tenantJoinFilter('class_schedules', 's') . "
            LEFT JOIN users u ON u.id = a.user_id" . $this->tenantJoinFilter('users', 'u') . "
            LEFT JOIN class_notification_logs n
                   ON n.attendance_id = a.id
                  AND n.notice_type = ?" . $this->tenantJoinFilter('class_notification_logs', 'n') . "
            WHERE n.id IS NULL
              AND COALESCE(s.status, '') NOT IN ('canceled','cancelled','deleted')
              AND COALESCE(a.status, '') IN ('approved','attended')
              AND COALESCE(u.line_user_id, a.line_user_id, '') <> ''
              " . $this->tenant->andWhere('class_attendances', 'a') . "
              {$where}
            ORDER BY s.class_date ASC, s.start_time ASC, a.id ASC
            LIMIT {$limit}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$type], $this->tenant->params('class_attendances')));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function whereForType(string $type): string {
        if ($type === 'before_day') {
            return "AND s.class_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
        }

        if ($type === 'same_day') {
            return "AND s.class_date = CURDATE()
                    AND CONCAT(s.class_date, ' ', COALESCE(NULLIF(s.start_time, ''), '00:00:00')) >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        }

        if ($type === 'after_class') {
            return "AND (a.attended_at IS NOT NULL OR a.status = 'attended')
                    AND CONCAT(s.class_date, ' ', COALESCE(NULLIF(s.end_time, ''), '23:59:00')) <= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        }

        return '';
    }

    private function buildMessage(string $type, array $target): string {
        $date = !empty($target['class_date']) ? date('Y/m/d', strtotime((string)$target['class_date'])) : '';
        $start = substr((string)($target['start_time'] ?? ''), 0, 5);
        $end = substr((string)($target['end_time'] ?? ''), 0, 5);
        $time = trim($start . ($end !== '' ? '-' . $end : ''));
        $title = (string)($target['title'] ?? 'AIアート教室');
        $location = trim((string)($target['location'] ?? ''));
        $onlineUrl = trim((string)($target['zoom_url'] ?? ''));

        if ($type === 'before_day') {
            return "【AIアート教室】明日のご案内\n\n"
                . "{$date} {$time}\n{$title}\n\n"
                . ($location !== '' ? "会場：{$location}\n" : '')
                . ($onlineUrl !== '' ? "オンラインURL：{$onlineUrl}\n" : '')
                . "\n当日はLINEメニューの「参加」から出席確認をお願いします。";
        }

        if ($type === 'same_day') {
            return "【AIアート教室】本日のご案内\n\n"
                . "{$date} {$time}\n{$title}\n\n"
                . "到着後、LINEメニューの「参加」ボタンを押して出席確認してください。";
        }

        return "【AIアート教室】ご参加ありがとうございました\n\n"
            . "{$date}\n{$title}\n\n"
            . "作品の保存や共有、次回予約はLINEメニューから確認できます。\n"
            . "分からないことがあれば、このメッセージに返信してください。";
    }

    private function markSent(string $type, array $target): void {
        [$columns, $values] = $this->tenant->assignInsert(
            'class_notification_logs',
            ['notice_type', 'attendance_id', 'schedule_id', 'user_id', 'line_user_id'],
            [
            $type,
            (int)$target['attendance_id'],
            (int)$target['schedule_id'],
            (int)$target['user_id'],
            (string)$target['line_user_id'],
            ]
        );
        $columnSql = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO class_notification_logs ({$columnSql}, sent_at, created_at)
             VALUES ({$placeholders}, NOW(), NOW())"
        );
        $stmt->execute($values);
    }

    private function ensureTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS class_notification_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id BIGINT UNSIGNED NULL,
                notice_type VARCHAR(32) NOT NULL,
                attendance_id BIGINT UNSIGNED NOT NULL,
                schedule_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                line_user_id VARCHAR(255) NOT NULL,
                sent_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tenant_attendance_notice (tenant_id, attendance_id, notice_type),
                KEY idx_class_notification_logs_tenant_id (tenant_id),
                KEY idx_notice_type (notice_type),
                KEY idx_schedule (schedule_id),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS class_followup_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id BIGINT UNSIGNED NULL,
                attendance_id BIGINT UNSIGNED NOT NULL,
                schedule_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                line_user_id VARCHAR(255) NOT NULL,
                sent_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tenant_attendance_followup (tenant_id, attendance_id),
                KEY idx_class_followup_logs_tenant_id (tenant_id),
                KEY idx_schedule (schedule_id),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        foreach (['class_notification_logs', 'class_followup_logs'] as $table) {
            try {
                $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id");
            } catch (Throwable $e) {
            }
            try {
                $this->pdo->exec("ALTER TABLE {$table} ADD INDEX idx_{$table}_tenant_id (tenant_id)");
            } catch (Throwable $e) {
            }
            if ($this->tenant->isDefaultTenant() && $this->tenant->tenantId()) {
                try {
                    $stmt = $this->pdo->prepare("UPDATE {$table} SET tenant_id = ? WHERE tenant_id IS NULL");
                    $stmt->execute([(int)$this->tenant->tenantId()]);
                } catch (Throwable $e) {
                }
            }
        }
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }
}
