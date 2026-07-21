<?php
// app/Controllers/LiffCalendarController.php

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';
require_once BASE_PATH . '/app/Services/CommonIntegrationService.php';

class LiffCalendarController {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->ensureWaitlistTable();
    }

    public function show(): void {
        $settings = Settings::all();
        $liffId = trim((string)($settings['liff_id'] ?? ''));
        $lineBasicId = trim((string)($settings['line_basic_id'] ?? ''));
        if ($lineBasicId !== '' && $lineBasicId[0] !== '@') {
            $lineBasicId = '@' . $lineBasicId;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $baseUrl = $host ? ($scheme . '://' . $host) : rtrim((string)($settings['app_base_url'] ?? ''), '/');
        $friendUrl = $lineBasicId !== '' ? 'https://line.me/R/ti/p/' . $lineBasicId : '';
        $liffDirectUrl = $liffId !== '' ? 'https://liff.line.me/' . rawurlencode($liffId) : '';

        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*)
                      FROM class_attendances a
                     WHERE a.schedule_id = s.id
                       " . $this->tenantSubqueryFilter('class_attendances', 'a') . "
                       AND a.status IN ('pending','approved')) AS total_applicants,
                   (SELECT COUNT(*)
                      FROM class_waitlists w
                     WHERE w.schedule_id = s.id
                       " . $this->tenantSubqueryFilter('class_waitlists', 'w') . "
                       AND w.status = 'waiting') AS waitlist_count
            FROM class_schedules s
            WHERE s.class_date >= CURDATE()
              AND s.status IN ('scheduled','active')
              " . $this->tenant->andWhere('class_schedules', 's') . "
            ORDER BY s.class_date ASC, s.start_time ASC
        ");
        $stmt->execute($this->tenant->params('class_schedules'));
        $schedules = $stmt->fetchAll();

        $events = [];
        foreach ($schedules as $s) {
            $capacity = (int)($s['capacity'] ?? 0);
            $reserved = (int)($s['total_applicants'] ?? 0);
            $events[] = [
                'id' => (int)$s['id'],
                'date' => (string)$s['class_date'],
                'title' => (string)($s['title'] ?? 'AIアート教室'),
                'start' => substr((string)($s['start_time'] ?? ''), 0, 5),
                'end' => substr((string)($s['end_time'] ?? ''), 0, 5),
                'capacity' => $capacity,
                'reserved' => $reserved,
                'waitlist' => (int)($s['waitlist_count'] ?? 0),
                'full' => $capacity > 0 && $reserved >= $capacity,
                'format' => (string)($s['event_format'] ?? 'realtime'),
                'location' => (string)($s['location'] ?? ''),
                'organizer' => (string)($s['organizer'] ?? ''),
                'fee' => (int)($s['fee'] ?? 0),
                'checkin_open' => substr((string)($s['checkin_open'] ?? ''), 0, 5),
                'checkin_close' => substr((string)($s['checkin_close'] ?? ''), 0, 5),
            ];
        }

        header('Content-Type: text/html; charset=UTF-8');
        require BASE_PATH . '/app/Views/liff/calendar.php';
    }

    public function reserve(): void {
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->json(false, '予約情報を読み取れませんでした。もう一度お試しください。');
            return;
        }

        $idToken = trim((string)($input['idToken'] ?? ''));
        $scheduleId = (int)($input['scheduleId'] ?? 0);
        $displayName = trim((string)($input['displayName'] ?? ''));
        $pictureUrl = trim((string)($input['pictureUrl'] ?? ''));

        if ($idToken === '' || $scheduleId <= 0) {
            $this->json(false, 'LINE認証が完了していません。LINEアプリ内で予約ページを開いてください。');
            return;
        }

        $lineUserId = $this->verifyIdToken($idToken);
        if (!$lineUserId) {
            $this->json(false, 'LINE認証に失敗しました。時間を置いてもう一度お試しください。');
            return;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM class_schedules WHERE id = ? AND status IN ('scheduled','active')" . $this->tenant->andWhere('class_schedules'));
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_schedules')));
        $schedule = $stmt->fetch();
        if (!$schedule) {
            $this->json(false, 'この教室は現在予約できません。');
            return;
        }

        $user = $this->upsertUser($lineUserId, $displayName, $pictureUrl);
        CommonIntegrationService::registerSafely(
            (int)$user['id'],
            $lineUserId,
            trim((string)($input['referralToken'] ?? $input['referral_token'] ?? ''))
        );
        if (in_array((string)($user['status'] ?? 'active'), ['suspended', 'banned'], true)) {
            $this->json(false, 'このアカウントでは予約できません。管理者へお問い合わせください。');
            return;
        }

        $existing = $this->findExistingAttendance($scheduleId, (int)$user['id']);
        if ($existing && !in_array((string)$existing['status'], ['cancelled', 'canceled', 'deleted'], true)) {
            if (($existing['payment_status'] ?? '') === 'unpaid' && (int)($existing['payment_amount'] ?? 0) > 0) {
                $payment = $this->createAttendanceCheckout($schedule, $user, $lineUserId, (int)$existing['payment_amount'], $existing);
                if ($payment['ok']) {
                    $this->json(true, '参加費のお支払いへ進みます。', [
                        'payment_required' => true,
                        'payment_url' => $payment['url'],
                    ]);
                    return;
                }
                $this->json(false, $payment['message']);
                return;
            }
            $this->json(true, $existing['status'] === 'approved' ? 'すでに承認済みです。' : 'すでに予約済みです。', ['already' => true]);
            return;
        }

        if ($this->isFull($schedule)) {
            $position = $this->registerWaitlist($schedule, $user, $lineUserId);
            $this->json(true, "満席のためキャンセル待ちに登録しました。現在 {$position} 番目です。", [
                'waitlist' => true,
                'position' => $position,
            ]);
            return;
        }

        $cancelledAttendance = $existing ?: null;
        $judge = $this->judgeBilling($user, $schedule);
        if ($judge['type'] === 'paid' && $judge['amount'] > 0) {
            $payment = $this->createAttendanceCheckout($schedule, $user, $lineUserId, $judge['amount'], $cancelledAttendance);
            if ($payment['ok']) {
                $this->json(true, '参加費のお支払いへ進みます。', [
                    'payment_required' => true,
                    'payment_url' => $payment['url'],
                ]);
                return;
            }
            $this->json(false, $payment['message']);
            return;
        }

        $autoApprove = !empty($schedule['auto_approve']);
        $status = $autoApprove ? 'approved' : 'pending';
        $paymentStatus = $judge['type'] === 'paid' ? 'unpaid' : $judge['type'];

        if ($cancelledAttendance) {
            $this->pdo->prepare("
                UPDATE class_attendances
                   SET status = ?,
                       payment_status = ?,
                       payment_amount = ?,
                       approved_at = " . ($autoApprove ? 'NOW()' : 'NULL') . ",
                       attended_at = NULL,
                       paid_at = NULL,
                       stripe_session_id = NULL,
                       updated_at = NOW()
                 WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
            ")->execute(array_merge([$status, $paymentStatus, (int)$judge['amount'], (int)$cancelledAttendance['id']], $this->tenant->params('class_attendances')));
        } else {
            $this->insertTenantRow('class_attendances', [
                'schedule_id' => $scheduleId,
                'user_id' => (int)$user['id'],
                'line_user_id' => $lineUserId,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'payment_amount' => (int)$judge['amount'],
                'approved_at' => $autoApprove ? date('Y-m-d H:i:s') : null,
            ]);
        }

        $this->logReservationEvent($schedule, $user, $status, $paymentStatus, (int)$judge['amount']);
        $message = $autoApprove
            ? '予約が完了し、承認されました。当日は参加ボタンから出席確認をしてください。'
            : '予約を受け付けました。承認後にLINEでお知らせします。';
        $this->json(true, $message, ['auto' => $autoApprove]);
    }

    public function waitlistStatus(): void {
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->json(false, '確認情報を読み取れませんでした。');
            return;
        }

        $idToken = trim((string)($input['idToken'] ?? ''));
        if ($idToken === '') {
            $this->json(false, 'LINE認証が完了していません。');
            return;
        }

        $lineUserId = $this->verifyIdToken($idToken);
        if (!$lineUserId) {
            $this->json(false, 'LINE認証に失敗しました。');
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT w.schedule_id
            FROM class_waitlists w
            LEFT JOIN users u ON u.id = w.user_id" . $this->tenantSubqueryFilter('users', 'u') . "
            WHERE w.status = 'waiting'
              AND COALESCE(u.line_user_id, w.line_user_id) = ?
              " . $this->tenant->andWhere('class_waitlists', 'w') . "
        ");
        $stmt->execute(array_merge([$lineUserId], $this->tenant->params('class_waitlists')));
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'schedule_id'));

        $this->json(true, 'キャンセル待ち状況を取得しました。', ['waitlist_schedule_ids' => $ids]);
    }

    public function cancelWaitlist(): void {
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->json(false, '取消情報を読み取れませんでした。もう一度お試しください。');
            return;
        }

        $idToken = trim((string)($input['idToken'] ?? ''));
        $scheduleId = (int)($input['scheduleId'] ?? 0);
        if ($idToken === '' || $scheduleId <= 0) {
            $this->json(false, 'LINE認証が完了していません。LINEアプリ内で予約ページを開いてください。');
            return;
        }

        $lineUserId = $this->verifyIdToken($idToken);
        if (!$lineUserId) {
            $this->json(false, 'LINE認証に失敗しました。時間を置いてもう一度お試しください。');
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE class_waitlists w
            LEFT JOIN users u ON u.id = w.user_id" . $this->tenantSubqueryFilter('users', 'u') . "
               SET w.status = 'cancelled',
                   w.updated_at = NOW()
             WHERE w.schedule_id = ?
               AND w.status = 'waiting'
               AND COALESCE(u.line_user_id, w.line_user_id) = ?
               " . $this->tenant->andWhere('class_waitlists', 'w') . "
        ");
        $stmt->execute(array_merge([$scheduleId, $lineUserId], $this->tenant->params('class_waitlists')));

        if ($stmt->rowCount() <= 0) {
            $this->json(false, 'キャンセル待ち情報が見つかりませんでした。');
            return;
        }

        $this->json(true, 'キャンセル待ちを取り消しました。');
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
                UNIQUE KEY uq_waitlist_tenant_schedule_user (tenant_id, schedule_id, user_id),
                KEY idx_class_waitlists_tenant (tenant_id),
                KEY idx_schedule_status (schedule_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM class_waitlists")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tenant_id', $columns, true)) {
                $this->pdo->exec('ALTER TABLE class_waitlists ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id');
                $this->pdo->exec('ALTER TABLE class_waitlists ADD INDEX idx_class_waitlists_tenant (tenant_id)');
            }
        } catch (Throwable $e) {
            // TenantDataService also repairs this table from client management.
        }
    }

    private function isFull(array $schedule): bool {
        $capacity = (int)($schedule['capacity'] ?? 0);
        if ($capacity <= 0) {
            return false;
        }
        $cnt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM class_attendances
            WHERE schedule_id = ?
              AND status IN ('pending','approved')
              " . $this->tenant->andWhere('class_attendances') . "
        ");
        $cnt->execute(array_merge([(int)$schedule['id']], $this->tenant->params('class_attendances')));
        return (int)$cnt->fetchColumn() >= $capacity;
    }

    private function registerWaitlist(array $schedule, array $user, string $lineUserId): int {
        $stmt = $this->pdo->prepare("SELECT * FROM class_waitlists WHERE schedule_id = ? AND user_id = ?" . $this->tenant->andWhere('class_waitlists') . " LIMIT 1");
        $stmt->execute(array_merge([(int)$schedule['id'], (int)$user['id']], $this->tenant->params('class_waitlists')));
        $existing = $stmt->fetch();

        if ($existing && (string)$existing['status'] === 'waiting') {
            return $this->waitlistPosition((int)$schedule['id'], (int)$existing['id']);
        }

        if ($existing) {
            $this->pdo->prepare("
                UPDATE class_waitlists
                   SET status = 'waiting',
                       line_user_id = ?,
                       display_name = ?,
                       notified_at = NULL,
                       updated_at = NOW()
                 WHERE id = ?" . $this->tenant->andWhere('class_waitlists') . "
            ")->execute(array_merge([$lineUserId, (string)($user['display_name'] ?? ''), (int)$existing['id']], $this->tenant->params('class_waitlists')));
            return $this->waitlistPosition((int)$schedule['id'], (int)$existing['id']);
        }

        $id = $this->insertTenantRow('class_waitlists', [
            'schedule_id' => (int)$schedule['id'],
            'user_id' => (int)$user['id'],
            'line_user_id' => $lineUserId,
            'display_name' => (string)($user['display_name'] ?? ''),
            'status' => 'waiting',
        ]);
        return $this->waitlistPosition((int)$schedule['id'], $id);
    }

    private function waitlistPosition(int $scheduleId, int $waitlistId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM class_waitlists
            WHERE schedule_id = ?
              AND status = 'waiting'
              AND id <= ?
              " . $this->tenant->andWhere('class_waitlists') . "
        ");
        $stmt->execute(array_merge([$scheduleId, $waitlistId], $this->tenant->params('class_waitlists')));
        return max(1, (int)$stmt->fetchColumn());
    }

    private function findExistingAttendance(int $scheduleId, int $userId): ?array {
        $exist = $this->pdo->prepare("SELECT * FROM class_attendances WHERE schedule_id = ? AND user_id = ?" . $this->tenant->andWhere('class_attendances') . " LIMIT 1");
        $exist->execute(array_merge([$scheduleId, $userId], $this->tenant->params('class_attendances')));
        $row = $exist->fetch();
        return $row ?: null;
    }

    private function verifyIdToken(string $idToken): ?string {
        $channelId = Settings::get('liff_channel_id', '');
        if ($channelId === '') {
            $channelId = Settings::get('line_login_channel_id', '');
        }
        if ($channelId === '') {
            return null;
        }

        $ch = curl_init('https://api.line.me/oauth2/v2.1/verify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(['id_token' => $idToken, 'client_id' => $channelId]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$res) {
            return null;
        }
        $data = json_decode($res, true);
        return is_array($data) ? ($data['sub'] ?? null) : null;
    }

    private function upsertUser(string $lineUserId, string $displayName, string $pictureUrl): array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE line_user_id = ?" . $this->tenant->andWhere('users'));
        $userParams = array_merge([$lineUserId], $this->tenant->params('users'));
        $stmt->execute($userParams);
        $user = $stmt->fetch();
        if ($user) {
            $this->pdo->prepare("
                UPDATE users
                   SET display_name = COALESCE(NULLIF(?, ''), display_name),
                       picture_url = COALESCE(NULLIF(?, ''), picture_url),
                       updated_at = NOW()
                 WHERE id = ?" . $this->tenant->andWhere('users') . "
            ")->execute(array_merge([$displayName, $pictureUrl, (int)$user['id']], $this->tenant->params('users')));
            $stmt->execute($userParams);
            return $stmt->fetch();
        }

        $this->insertTenantRow('users', [
            'line_user_id' => $lineUserId,
            'display_name' => $displayName,
            'picture_url' => $pictureUrl,
            'status' => 'active',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);
        $stmt->execute($userParams);
        return $stmt->fetch();
    }

    private function judgeBilling(array $user, array $schedule): array {
        $billingPath = BASE_PATH . '/app/Services/BillingService.php';
        if (is_file($billingPath)) {
            require_once $billingPath;
            if (class_exists('BillingService')) {
                try {
                    return (new BillingService())->judge($user, $schedule);
                } catch (Throwable $e) {
                    // Fall back to a simple fee check below.
                }
            }
        }

        $fee = (int)($schedule['fee'] ?? 0);
        return $fee > 0
            ? ['type' => 'paid', 'amount' => $fee, 'message' => '有料']
            : ['type' => 'free', 'amount' => 0, 'message' => '無料'];
    }

    private function createAttendanceCheckout(array $schedule, array $user, string $lineUserId, int $amount, ?array $attendance): array {
        $stripePath = BASE_PATH . '/app/Services/StripeService.php';
        if (!is_file($stripePath)) {
            return ['ok' => false, 'message' => '決済サービスが設定されていません。管理者へお問い合わせください。'];
        }
        require_once $stripePath;
        if (!class_exists('StripeService')) {
            return ['ok' => false, 'message' => '決済サービスが利用できません。管理者へお問い合わせください。'];
        }

        $stripe = new StripeService();
        if (method_exists($stripe, 'isConfigured') && !$stripe->isConfigured()) {
            return ['ok' => false, 'message' => '決済設定が未完了です。管理者へお問い合わせください。'];
        }

        $attendanceId = (int)($attendance['id'] ?? 0);
        if ($attendanceId <= 0) {
            $attendanceId = $this->insertTenantRow('class_attendances', [
                'schedule_id' => (int)$schedule['id'],
                'user_id' => (int)$user['id'],
                'line_user_id' => $lineUserId,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'payment_amount' => $amount,
            ]);
        } else {
            $this->pdo->prepare("
                UPDATE class_attendances
                   SET status = 'pending',
                       payment_status = 'unpaid',
                       payment_amount = ?,
                       attended_at = NULL,
                       approved_at = NULL,
                       paid_at = NULL,
                       updated_at = NOW()
                 WHERE id = ?" . $this->tenant->andWhere('class_attendances') . "
            ")->execute(array_merge([$amount, $attendanceId], $this->tenant->params('class_attendances')));
        }

        $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        $checkout = $stripe->createCheckout(
            $amount,
            (string)$schedule['title'] . ' 参加費',
            ['attendance_id' => $attendanceId, 'schedule_id' => (int)$schedule['id'], 'user_id' => (int)$user['id']],
            $base . '/liff/paid?attendance=' . $attendanceId . $this->tenantUrlSuffix('&'),
            $base . '/liff/calendar' . $this->tenantUrlSuffix('?')
        );

        if (!$checkout || empty($checkout['url'])) {
            return ['ok' => false, 'message' => '決済ページの作成に失敗しました。時間を置いて再度お試しください。'];
        }

        $this->pdo->prepare("UPDATE class_attendances SET stripe_session_id = ? WHERE id = ?" . $this->tenant->andWhere('class_attendances'))
            ->execute(array_merge([$checkout['id'] ?? '', $attendanceId], $this->tenant->params('class_attendances')));

        return ['ok' => true, 'url' => $checkout['url'], 'attendance_id' => $attendanceId];
    }

    private function logReservationEvent(array $schedule, array $user, string $status, string $paymentStatus, int $amount): void {
        $path = BASE_PATH . '/app/Services/ReservationEventLog.php';
        if (!is_file($path)) {
            return;
        }
        require_once $path;
        if (!class_exists('ReservationEventLog')) {
            return;
        }
        try {
            ReservationEventLog::record($this->pdo, [
                'schedule_id' => (int)$schedule['id'],
                'user_id' => (int)$user['id'],
                'line_user_id' => (string)$user['line_user_id'],
                'event_type' => $status === 'approved' ? 'approved' : 'reserved',
                'payment_status' => $paymentStatus,
                'amount' => $amount,
                'message' => 'LIFF予約',
            ]);
        } catch (Throwable $e) {
            // Logging failure must not block a reservation.
        }
    }

    private function json(bool $ok, string $message, array $extra = []): void {
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    }

    private function insertTenantRow(string $table, array $data): int {
        $columns = array_keys($data);
        $values = array_values($data);
        [$columns, $values] = $this->tenant->assignInsert($table, $columns, $values);
        $columnSql = implode(', ', array_map(static fn($column) => '`' . $column . '`', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->pdo->prepare("INSERT INTO `{$table}` ({$columnSql}, created_at, updated_at) VALUES ({$placeholders}, NOW(), NOW())");
        $stmt->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    private function tenantSubqueryFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }

    private function tenantUrlSuffix(string $separator): string {
        $tenant = Settings::currentTenant();
        $key = trim((string)($tenant['tenant_key'] ?? ''));
        return $key !== '' ? $separator . 'tenant=' . rawurlencode($key) : '';
    }
}
