<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/Logger.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';
require_once BASE_PATH . '/app/Services/GenerationTestAccessService.php';

class AdminUserController {
    private PDO $pdo;
    private LineService $line;
    private TenantScopeService $tenant;
    private GenerationTestAccessService $generationTestAccess;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->generationTestAccess = new GenerationTestAccessService($this->pdo);
        $this->line = new LineService();
        $this->ensureProfileColumns();
        $this->ensureGenerationUsageTable();
    }

    public function index(): void {
        $where = [];
        $params = [];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $tenantWhere = $this->tenant->where('users', 'u');
        if ($tenantWhere !== '') {
            $where[] = $tenantWhere;
            $params = array_merge($params, $this->tenant->params('users'));
        }
        if (!empty($_GET['status'])) {
            $where[] = 'u.status = ?';
            $params[] = (string)$_GET['status'];
        }
        if (!empty($_GET['keyword'])) {
            $where[] = '(u.display_name LIKE ? OR u.real_name LIKE ? OR u.phone LIKE ? OR u.address LIKE ?)';
            $keyword = '%' . (string)$_GET['keyword'] . '%';
            array_push($params, $keyword, $keyword, $keyword, $keyword);
        }
        $whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $this->pdo->prepare('SELECT COUNT(*) FROM users u' . $whereClause);
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $sql = "
            SELECT u.*,
                   (SELECT COUNT(*) FROM image_requests r
                     WHERE r.user_id = u.id" . $this->tenantJoinFilter('image_requests', 'r') . ") AS total_requests,
                   (SELECT COUNT(*) FROM image_requests r
                     WHERE r.user_id = u.id" . $this->tenantJoinFilter('image_requests', 'r') . "
                       AND DATE(r.created_at) = CURDATE()
                       AND COALESCE(r.status, '') NOT IN ('failed','cancelled','canceled','deleted')) AS today_requests,
                   (SELECT COUNT(*) FROM class_attendances a
                     WHERE a.user_id = u.id" . $this->tenantJoinFilter('class_attendances', 'a') . "
                       AND a.status = 'approved') AS total_classes
            FROM users u{$whereClause}
            ORDER BY u.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        $totalPages = (int)ceil($total / $perPage);

        require BASE_PATH . '/app/Views/admin/users.php';
    }

    public function show(int $id): void {
        $user = $this->findUser($id);
        if (!$user) {
            http_response_code(404);
            echo 'ユーザーが見つかりません。';
            return;
        }

        $scheduleJoin = $this->tenantJoinFilter('class_schedules', 's');
        $stmtA = $this->pdo->prepare("
            SELECT a.*, s.title, s.class_date, s.start_time
            FROM class_attendances a
            LEFT JOIN class_schedules s ON s.id = a.schedule_id{$scheduleJoin}
            WHERE a.user_id = ?" . $this->tenant->andWhere('class_attendances', 'a') . "
            ORDER BY s.class_date DESC
            LIMIT 20
        ");
        $stmtA->execute(array_merge([$id], $this->tenant->params('class_attendances')));
        $attendances = $stmtA->fetchAll();

        $generatedFilter = $this->tenantJoinFilter('generated_images', 'gi');
        $stmtR = $this->pdo->prepare("
            SELECT r.*,
                   (SELECT COUNT(*) FROM generated_images gi
                     WHERE gi.request_id = r.id{$generatedFilter}) AS image_count
            FROM image_requests r
            WHERE r.user_id = ?" . $this->tenant->andWhere('image_requests', 'r') . "
            ORDER BY r.created_at DESC
            LIMIT 20
        ");
        $stmtR->execute(array_merge([$id], $this->tenant->params('image_requests')));
        $requests = $stmtR->fetchAll();

        require_once BASE_PATH . '/app/Services/TicketLog.php';
        $ticketLogs = TicketLog::recent($this->pdo, 30, $id);
        $todayUsage = $this->getTodayGenerationUsage($user);
        require BASE_PATH . '/app/Views/admin/user_detail.php';
    }

    public function setMemberType(int $id): void {
        $this->requireUser($id);
        require_once BASE_PATH . '/app/Services/BillingService.php';
        (new BillingService())->setMemberType($id, (string)($_POST['member_type'] ?? 'none'));
        $this->redirectUser($id, 'updated=1');
    }

    public function addTickets(int $id): void {
        $this->requireUser($id);
        require_once BASE_PATH . '/app/Services/BillingService.php';
        $count = (int)($_POST['ticket_count'] ?? 0);
        $memo = trim((string)($_POST['ticket_memo'] ?? ''));
        if ($count !== 0) {
            (new BillingService())->addTickets($id, $count, 'manual', $memo !== '' ? $memo : '管理画面で手動変更');
        }
        $this->redirectUser($id, 'updated=1');
    }

    public function updateStatus(int $id): void {
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            http_response_code(400);
            return;
        }
        $user = $this->requireUser($id);
        $stmt = $this->pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?" . $this->tenant->andWhere('users'));
        $stmt->execute(array_merge([$status, $id], $this->tenant->params('users')));

        if ($status === 'suspended' && !empty($user['line_user_id'])) {
            $this->line->pushText((string)$user['line_user_id'], "アカウントが一時停止されました。\n詳しくは管理者へお問い合わせください。");
        }
        Logger::info('admin', "ユーザーステータス変更 user_id={$id} status={$status}");
        $this->redirectUser($id, 'updated=1');
    }

    public function updateMemo(int $id): void {
        $this->requireUser($id);
        $memo = trim((string)($_POST['memo'] ?? ''));
        $this->pdo->prepare("UPDATE users SET memo = ?, updated_at = NOW() WHERE id = ?" . $this->tenant->andWhere('users'))
            ->execute(array_merge([$memo, $id], $this->tenant->params('users')));
        $this->redirectUser($id, 'updated=1');
    }

    public function sendMessage(int $id): void {
        $user = $this->requireUser($id);
        $message = trim((string)($_POST['message'] ?? ''));
        if ($message !== '' && !empty($user['line_user_id'])) {
            $this->line->pushText((string)$user['line_user_id'], $message);
            Logger::info('admin', "個別LINE送信 user_id={$id}");
        }
        $this->redirectUser($id, $message !== '' ? 'sent=1' : '');
    }

    public function setGenerationUsage(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $user = $this->requireUser($id);
        $date = date('Y-m-d');
        $delete = $this->pdo->prepare(
            "DELETE FROM image_request_usage_overrides
             WHERE user_id = ? AND usage_date = ?" . $this->tenant->andWhere('image_request_usage_overrides')
        );
        $delete->execute(array_merge([$id, $date], $this->tenant->params('image_request_usage_overrides')));

        if ((string)($_POST['usage_action'] ?? 'set') === 'clear') {
            Logger::info('admin', "generation usage override cleared user_id={$id}");
            $this->redirectUser($id, 'usage_updated=1');
        }

        $count = max(0, min(999, (int)($_POST['override_count'] ?? 0)));
        $memo = trim((string)($_POST['usage_memo'] ?? ''));
        [$columns, $values] = $this->tenant->assignInsert(
            'image_request_usage_overrides',
            ['user_id', 'line_user_id', 'usage_date', 'override_count', 'memo'],
            [$id, (string)($user['line_user_id'] ?? ''), $date, $count, $memo]
        );
        $columnSql = implode(', ', array_map(static fn($column) => '`' . $column . '`', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $this->pdo->prepare("INSERT INTO image_request_usage_overrides ({$columnSql}, created_at, updated_at) VALUES ({$placeholders}, NOW(), NOW())")
            ->execute($values);
        Logger::info('admin', "generation usage override set user_id={$id} count={$count}");
        $this->redirectUser($id, 'usage_updated=1');
    }

    public function setGenerationTestMode(int $id): void {
        if (function_exists('verify_csrf')) {
            verify_csrf();
        }
        $this->requireUser($id);

        $enabled = (string)($_POST['generation_test_enabled'] ?? '') === '1';
        $rawUntil = trim((string)($_POST['generation_test_until'] ?? ''));
        $until = null;
        if ($rawUntil !== '') {
            $date = DateTime::createFromFormat('Y-m-d\TH:i', $rawUntil);
            $errors = DateTime::getLastErrors();
            $invalid = !$date || ($errors !== false && (
                (int)($errors['warning_count'] ?? 0) > 0
                || (int)($errors['error_count'] ?? 0) > 0
            ));
            if ($invalid) {
                $this->redirectUser($id, 'test_mode_error=invalid_until');
            }
            $until = $date->format('Y-m-d H:i:s');
            if ($enabled && strtotime($until) <= time()) {
                $this->redirectUser($id, 'test_mode_error=expired_until');
            }
        }

        $memo = trim((string)($_POST['generation_test_memo'] ?? ''));
        if (function_exists('mb_substr')) {
            $memo = mb_substr($memo, 0, 255);
        } else {
            $memo = substr($memo, 0, 255);
        }

        try {
            $saved = $this->generationTestAccess->saveForUser($id, $enabled, $until, $memo);
            if (!$saved) {
                $this->redirectUser($id, 'test_mode_error=save_failed');
            }
            Logger::info(
                'admin',
                'generation test mode updated tenant_id=' . $this->tenant->tenantId()
                . " user_id={$id} enabled=" . ($enabled ? '1' : '0')
            );
            $this->redirectUser($id, 'test_mode_updated=1');
        } catch (Throwable $e) {
            Logger::error('admin', "generation test mode update failed user_id={$id}: " . $e->getMessage());
            $this->redirectUser($id, 'test_mode_error=save_failed');
        }
    }

    private function getTodayGenerationUsage(array $user): array {
        $lineUserId = (string)($user['line_user_id'] ?? '');
        $active = $this->countTodayRequests($lineUserId, false);
        $failed = $this->countTodayRequests($lineUserId, true);
        $stmt = $this->pdo->prepare("
            SELECT override_count, memo, updated_at
            FROM image_request_usage_overrides
            WHERE user_id = ? AND usage_date = CURDATE()" . $this->tenant->andWhere('image_request_usage_overrides') . "
            LIMIT 1
        ");
        $stmt->execute(array_merge([(int)$user['id']], $this->tenant->params('image_request_usage_overrides')));
        $override = $stmt->fetch();
        return [
            'actual_count' => $active,
            'failed_count' => $failed,
            'override_count' => $override ? (int)$override['override_count'] : null,
            'effective_count' => $override ? (int)$override['override_count'] : $active,
            'memo' => $override['memo'] ?? '',
            'updated_at' => $override['updated_at'] ?? null,
        ];
    }

    private function countTodayRequests(string $lineUserId, bool $failed): int {
        $statuses = $failed
            ? "IN ('failed','cancelled','canceled','deleted')"
            : "NOT IN ('failed','cancelled','canceled','deleted')";
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM image_requests
            WHERE line_user_id = ?
              AND DATE(created_at) = CURDATE()
              AND COALESCE(status, '') {$statuses}" . $this->tenant->andWhere('image_requests')
        );
        $stmt->execute(array_merge([$lineUserId], $this->tenant->params('image_requests')));
        return (int)$stmt->fetchColumn();
    }

    private function findUser(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?" . $this->tenant->andWhere('users'));
        $stmt->execute(array_merge([$id], $this->tenant->params('users')));
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function requireUser(int $id): array {
        $user = $this->findUser($id);
        if (!$user) {
            http_response_code(404);
            exit('ユーザーが見つかりません。');
        }
        return $user;
    }

    private function redirectUser(int $id, string $query = ''): void {
        header('Location: /admin/users/' . $id . ($query !== '' ? '?' . $query : ''));
        exit;
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }

    private function ensureGenerationUsageTable(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS image_request_usage_overrides (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id BIGINT UNSIGNED NULL,
                    user_id INT NOT NULL,
                    line_user_id VARCHAR(255) NOT NULL,
                    usage_date DATE NOT NULL,
                    override_count INT NOT NULL DEFAULT 0,
                    memo TEXT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    UNIQUE KEY uniq_tenant_user_usage_date (tenant_id, user_id, usage_date),
                    KEY idx_image_request_usage_tenant (tenant_id),
                    KEY idx_line_user_date (line_user_id, usage_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $columns = $this->pdo->query("SHOW COLUMNS FROM image_request_usage_overrides")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tenant_id', $columns, true)) {
                $this->pdo->exec('ALTER TABLE image_request_usage_overrides ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id');
                $this->pdo->exec('ALTER TABLE image_request_usage_overrides ADD INDEX idx_image_request_usage_tenant (tenant_id)');
            }
        } catch (Throwable $e) {
            Logger::error('admin', 'failed to ensure generation usage override table: ' . $e->getMessage());
        }
    }

    private function ensureProfileColumns(): void {
        foreach ([
            "ALTER TABLE users ADD COLUMN real_name VARCHAR(255) NULL AFTER display_name",
            "ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER picture_url",
            "ALTER TABLE users ADD COLUMN postal_code VARCHAR(20) NULL AFTER phone",
            "ALTER TABLE users ADD COLUMN address TEXT NULL AFTER postal_code",
            "ALTER TABLE users ADD COLUMN profile_note TEXT NULL AFTER address",
            "ALTER TABLE users ADD COLUMN profile_completed_at DATETIME NULL AFTER profile_note",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // Existing columns are expected.
            }
        }
    }
}
