<?php
// app/Services/WaitlistNotificationService.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/Logger.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class WaitlistNotificationService {
    private PDO $pdo;
    private LineService $line;
    private TenantScopeService $tenant;

    public function __construct(?PDO $pdo = null, ?LineService $line = null) {
        $this->pdo = $pdo ?: get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->line = $line ?: new LineService();
        $this->ensureWaitlistTable();
    }

    public function notifyOpenSlots(int $limit = 10): int {
        $limit = max(1, min(50, $limit));
        $schedules = $this->fetchSchedulesWithOpenSlots($limit);
        $sent = 0;

        foreach ($schedules as $schedule) {
            $open = $this->openSlots($schedule);
            if ($open <= 0) {
                continue;
            }

            $waiters = $this->fetchNotifiableWaiters((int)$schedule['id'], $open);
            foreach ($waiters as $waiter) {
                $lineUserId = trim((string)($waiter['line_user_id'] ?? ''));
                if ($lineUserId === '') {
                    continue;
                }

                $message = $this->buildMessage($schedule);
                if ($this->line->pushText($lineUserId, $message)) {
                    $this->markNotified((int)$waiter['id']);
                    $sent++;
                    usleep(150000);
                }
            }
        }

        if ($sent > 0) {
            Logger::info('class', "waitlist open-slot notifications sent={$sent}");
        }

        return $sent;
    }

    private function fetchSchedulesWithOpenSlots(int $limit): array {
        $sql = "
            SELECT s.*,
                   (SELECT COUNT(*)
                      FROM class_attendances a
                     WHERE a.schedule_id = s.id
                       " . $this->tenantLiteralFilter('class_attendances', 'a') . "
                       AND a.status IN ('pending','approved','attended')) AS active_count,
                   (SELECT COUNT(*)
                      FROM class_waitlists w
                     WHERE w.schedule_id = s.id
                       " . $this->tenantLiteralFilter('class_waitlists', 'w') . "
                       AND w.status = 'waiting'
                       AND w.notified_at IS NULL) AS waiting_count
            FROM class_schedules s
            WHERE s.class_date >= CURDATE()
              AND s.status IN ('scheduled','active')
              AND COALESCE(s.capacity, 0) > 0
              " . $this->tenantLiteralFilter('class_schedules', 's') . "
            HAVING active_count < s.capacity
               AND waiting_count > 0
            ORDER BY s.class_date ASC, s.start_time ASC
            LIMIT {$limit}
        ";
        return $this->pdo->query($sql)->fetchAll();
    }

    private function openSlots(array $schedule): int {
        $capacity = (int)($schedule['capacity'] ?? 0);
        $active = (int)($schedule['active_count'] ?? 0);
        return max(0, $capacity - $active);
    }

    private function fetchNotifiableWaiters(int $scheduleId, int $limit): array {
        $limit = max(1, min(20, $limit));
        $stmt = $this->pdo->prepare("
            SELECT w.*, u.line_user_id AS user_line_id
            FROM class_waitlists w
            LEFT JOIN users u ON u.id = w.user_id" . $this->tenantJoinFilter('users', 'u') . "
            WHERE w.schedule_id = ?
              AND w.status = 'waiting'
              AND w.notified_at IS NULL
              " . $this->tenant->andWhere('class_waitlists', 'w') . "
            ORDER BY w.id ASC
            LIMIT {$limit}
        ");
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_waitlists')));
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (empty($row['line_user_id']) && !empty($row['user_line_id'])) {
                $row['line_user_id'] = $row['user_line_id'];
            }
        }
        unset($row);
        return $rows;
    }

    private function markNotified(int $waitlistId): void {
        $stmt = $this->pdo->prepare("
            UPDATE class_waitlists
               SET notified_at = NOW(),
                   updated_at = NOW()
             WHERE id = ?
               AND status = 'waiting'
               " . $this->tenant->andWhere('class_waitlists') . "
        ");
        $stmt->execute(array_merge([$waitlistId], $this->tenant->params('class_waitlists')));
    }

    private function buildMessage(array $schedule): string {
        $date = !empty($schedule['class_date']) ? date('Y/m/d', strtotime((string)$schedule['class_date'])) : '';
        $start = substr((string)($schedule['start_time'] ?? ''), 0, 5);
        $title = (string)($schedule['title'] ?? '教室');

        return "【AIアート教室】\n"
            . "キャンセル待ち中の教室に空きが出ました。\n\n"
            . "{$date} {$start}\n"
            . "{$title}\n\n"
            . "参加を希望される場合は、このメッセージに返信してください。\n"
            . "空き枠は先着順でご案内するため、満席になっている場合があります。";
    }

    private function ensureWaitlistTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS class_waitlists (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id BIGINT UNSIGNED NULL,
                schedule_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                line_user_id VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'waiting',
                notified_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tenant_schedule_user (tenant_id, schedule_id, user_id),
                KEY idx_class_waitlists_tenant_id (tenant_id),
                KEY idx_schedule_status (schedule_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        try {
            $this->pdo->exec("ALTER TABLE class_waitlists ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id");
        } catch (Throwable $e) {
        }
        try {
            $this->pdo->exec("ALTER TABLE class_waitlists ADD INDEX idx_class_waitlists_tenant_id (tenant_id)");
        } catch (Throwable $e) {
        }
        if ($this->tenant->isDefaultTenant() && $this->tenant->tenantId()) {
            try {
                $stmt = $this->pdo->prepare("UPDATE class_waitlists SET tenant_id = ? WHERE tenant_id IS NULL");
                $stmt->execute([(int)$this->tenant->tenantId()]);
            } catch (Throwable $e) {
            }
        }
    }

    private function tenantLiteralFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        return $this->tenantLiteralFilter($table, $alias);
    }
}
