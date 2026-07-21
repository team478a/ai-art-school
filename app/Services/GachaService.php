<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/LineService.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class GachaService {
    private PDO $pdo;
    private TenantScopeService $tenant;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->ensureTables();
        $this->ensureTenantColumns();
        $this->seedDefaults();
    }

    public function currentCampaign(): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM gacha_campaigns
            WHERE status = 'active'
              AND (starts_at IS NULL OR starts_at <= NOW())
              AND (ends_at IS NULL OR ends_at >= NOW())" . $this->tenant->andWhere('gacha_campaigns') . "
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute($this->tenant->params('gacha_campaigns'));
        $campaign = $stmt->fetch();
        if ($campaign) {
            return $campaign;
        }

        $this->insertTenantRecord('gacha_campaigns', [
            'name' => '戦国クリエイター入陣ガチャ',
            'status' => 'active',
            'default_expires_days' => 14,
        ], false, true);
        return $this->currentCampaign();
    }

    public function adminConfig(): array {
        $campaign = $this->currentCampaign();

        $rarityStmt = $this->pdo->prepare("
            SELECT *
            FROM gacha_rarities
            WHERE 1=1" . $this->tenant->andWhere('gacha_rarities') . "
            ORDER BY sort_order ASC, id ASC
        ");
        $rarityStmt->execute($this->tenant->params('gacha_rarities'));
        $rarities = $rarityStmt->fetchAll();

        $stmt = $this->pdo->prepare("
            SELECT p.*, r.code AS rarity_code, r.name AS rarity_name
            FROM gacha_prizes p
            LEFT JOIN gacha_rarities r ON r.id = p.rarity_id" . $this->tenantJoinFilter('gacha_rarities', 'r') . "
            WHERE (p.campaign_id IS NULL OR p.campaign_id = ?)" . $this->tenant->andWhere('gacha_prizes', 'p') . "
            ORDER BY p.sort_order ASC, p.id ASC
        ");
        $stmt->execute(array_merge([(int)$campaign['id']], $this->tenant->params('gacha_prizes')));

        return [
            'campaign' => $campaign,
            'rarities' => $rarities,
            'prizes' => $stmt->fetchAll(),
        ];
    }

    public function saveCampaignSettings(array $input): void {
        $campaign = $this->currentCampaign();
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            $name = (string)$campaign['name'];
        }

        $expiresDays = (int)($input['default_expires_days'] ?? 14);
        $expiresDays = max(1, min(365, $expiresDays));

        $stmt = $this->pdo->prepare("
            UPDATE gacha_campaigns
            SET name = ?, default_expires_days = ?, updated_at = NOW()
            WHERE id = ?" . $this->tenant->andWhere('gacha_campaigns') . "
        ");
        $stmt->execute(array_merge([$name, $expiresDays, (int)$campaign['id']], $this->tenant->params('gacha_campaigns')));
    }

    public function saveRaritySettings(array $input): void {
        $ids = $input['id'] ?? [];
        $names = $input['name'] ?? [];
        $weights = $input['weight'] ?? [];
        $sortOrders = $input['sort_order'] ?? [];
        $colors = $input['color'] ?? [];
        $videoUrls = $input['video_url'] ?? [];

        $stmt = $this->pdo->prepare("
            UPDATE gacha_rarities
            SET name = ?, weight = ?, sort_order = ?, color = ?, video_url = ?
            WHERE id = ?" . $this->tenant->andWhere('gacha_rarities') . "
        ");

        foreach ((array)$ids as $i => $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            $name = trim((string)($names[$i] ?? ''));
            if ($name === '') {
                continue;
            }
            $stmt->execute([
                $name,
                max(0, (int)($weights[$i] ?? 0)),
                (int)($sortOrders[$i] ?? 0),
                trim((string)($colors[$i] ?? '')),
                trim((string)($videoUrls[$i] ?? '')),
                $id,
                ...$this->tenant->params('gacha_rarities'),
            ]);
        }

        $newCode = strtoupper(trim((string)($input['new_code'] ?? '')));
        $newName = trim((string)($input['new_name'] ?? ''));
        if ($newCode !== '' && $newName !== '') {
            $this->insertTenantRecord('gacha_rarities', [
                'code' => $newCode,
                'name' => $newName,
                'weight' => max(0, (int)($input['new_weight'] ?? 0)),
                'sort_order' => (int)($input['new_sort_order'] ?? 0),
                'color' => trim((string)($input['new_color'] ?? '#64748b')),
                'video_url' => trim((string)($input['new_video_url'] ?? '')),
            ], true);
        }
    }

    public function savePrizeSettings(array $input): void {
        $ids = $input['id'] ?? [];
        $rarityIds = $input['rarity_id'] ?? [];
        $names = $input['name'] ?? [];
        $descriptions = $input['description'] ?? [];
        $expiresDays = $input['expires_days'] ?? [];
        $sortOrders = $input['sort_order'] ?? [];
        $activeIds = array_map('intval', (array)($input['active_id'] ?? []));

        $stmt = $this->pdo->prepare("
            UPDATE gacha_prizes
            SET rarity_id = ?, name = ?, description = ?, expires_days = ?, is_active = ?, sort_order = ?
            WHERE id = ?" . $this->tenant->andWhere('gacha_prizes') . "
        ");

        foreach ((array)$ids as $i => $id) {
            $id = (int)$id;
            $name = trim((string)($names[$i] ?? ''));
            $rarityId = (int)($rarityIds[$i] ?? 0);
            if ($id <= 0 || $rarityId <= 0 || $name === '') {
                continue;
            }
            $daysText = trim((string)($expiresDays[$i] ?? ''));
            $days = $daysText === '' ? null : max(1, min(365, (int)$daysText));
            $stmt->execute([
                $rarityId,
                $name,
                trim((string)($descriptions[$i] ?? '')),
                $days,
                in_array($id, $activeIds, true) ? 1 : 0,
                (int)($sortOrders[$i] ?? 0),
                $id,
                ...$this->tenant->params('gacha_prizes'),
            ]);
        }

        $newName = trim((string)($input['new_name'] ?? ''));
        $newRarityId = (int)($input['new_rarity_id'] ?? 0);
        if ($newName !== '' && $newRarityId > 0) {
            $newDaysText = trim((string)($input['new_expires_days'] ?? ''));
            $newDays = $newDaysText === '' ? null : max(1, min(365, (int)$newDaysText));
            $this->insertTenantRecord('gacha_prizes', [
                'campaign_id' => null,
                'rarity_id' => $newRarityId,
                'name' => $newName,
                'description' => trim((string)($input['new_description'] ?? '')),
                'reward_type' => 'purchase_interest',
                'expires_days' => $newDays,
                'is_active' => 1,
                'sort_order' => (int)($input['new_sort_order'] ?? 0),
            ]);
        }
    }

    public function adminSummary(): array {
        $campaign = $this->currentCampaign();
        $summary = [
            'campaign' => $campaign,
            'entitlements' => 0,
            'drawn' => 0,
            'interests' => 0,
        ];

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM gacha_entitlements WHERE campaign_id = ?' . $this->tenant->andWhere('gacha_entitlements')
        );
        $stmt->execute(array_merge([(int)$campaign['id']], $this->tenant->params('gacha_entitlements')));
        $summary['entitlements'] = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM gacha_results WHERE campaign_id = ?' . $this->tenant->andWhere('gacha_results')
        );
        $stmt->execute(array_merge([(int)$campaign['id']], $this->tenant->params('gacha_results')));
        $summary['drawn'] = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM gacha_purchase_interests WHERE status = ?' . $this->tenant->andWhere('gacha_purchase_interests')
        );
        $stmt->execute(array_merge(['interested'], $this->tenant->params('gacha_purchase_interests')));
        $summary['interests'] = (int)$stmt->fetchColumn();

        return $summary;
    }

    public function schedulesForGrant(): array {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id, s.title, s.class_date, s.start_time, s.end_time,
                COUNT(CASE WHEN a.attended_at IS NOT NULL THEN 1 END) AS attended_count,
                COUNT(DISTINCT ge.id) AS entitlement_count
            FROM class_schedules s
            LEFT JOIN class_attendances a ON a.schedule_id = s.id" . $this->tenantJoinFilter('class_attendances', 'a') . "
            LEFT JOIN gacha_entitlements ge ON ge.schedule_id = s.id" . $this->tenantJoinFilter('gacha_entitlements', 'ge') . "
            WHERE s.class_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)" . $this->tenant->andWhere('class_schedules', 's') . "
            GROUP BY s.id
            ORDER BY s.class_date DESC, s.start_time DESC
            LIMIT 30
        ");
        $stmt->execute($this->tenant->params('class_schedules'));
        return $stmt->fetchAll();
    }

    public function grantForSchedule(int $scheduleId, ?int $adminId = null): array {
        $campaign = $this->currentCampaign();
        $expiresDays = max(1, (int)($campaign['default_expires_days'] ?? 14));

        $stmt = $this->pdo->prepare("
            SELECT a.id AS attendance_id, a.schedule_id, a.user_id, COALESCE(a.line_user_id, u.line_user_id) AS line_user_id
            FROM class_attendances a
            LEFT JOIN users u ON u.id = a.user_id" . $this->tenantJoinFilter('users', 'u') . "
            WHERE a.schedule_id = ?
              AND a.attended_at IS NOT NULL
              AND COALESCE(a.line_user_id, u.line_user_id, '') <> ''
              " . $this->tenant->andWhere('class_attendances', 'a') . "
        ");
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('class_attendances')));
        $rows = $stmt->fetchAll();

        $created = 0;
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresDays * 86400));
        foreach ($rows as $row) {
            $id = $this->insertTenantRecord('gacha_entitlements', [
                'campaign_id' => (int)$campaign['id'],
                'attendance_id' => (int)$row['attendance_id'],
                'schedule_id' => (int)$row['schedule_id'],
                'user_id' => (int)$row['user_id'],
                'line_user_id' => (string)$row['line_user_id'],
                'granted_by_admin_id' => $adminId,
                'expires_at' => $expiresAt,
                'status' => 'active',
            ], true, true);
            if ($id > 0) {
                $created++;
            }
        }

        return ['eligible' => count($rows), 'created' => $created];
    }

    public function notifyForSchedule(int $scheduleId): array {
        $url = $this->absoluteUrl('/liff/gacha');
        $stmt = $this->pdo->prepare("
            SELECT ge.id, ge.line_user_id, ge.expires_at, s.title, s.class_date
            FROM gacha_entitlements ge
            LEFT JOIN class_schedules s ON s.id = ge.schedule_id" . $this->tenantJoinFilter('class_schedules', 's') . "
            WHERE ge.schedule_id = ?
              AND ge.status = 'active'
              AND ge.used_at IS NULL
              AND ge.expires_at >= NOW()
              AND ge.line_user_id <> ''
              " . $this->tenant->andWhere('gacha_entitlements', 'ge') . "
            ORDER BY ge.id ASC
        ");
        $stmt->execute(array_merge([$scheduleId], $this->tenant->params('gacha_entitlements')));
        $rows = $stmt->fetchAll();

        $line = new LineService();
        $sent = 0;
        foreach ($rows as $row) {
            $text = "ご参加ありがとうございました。\n\n戦国クリエイター入陣ガチャの参加権が付与されました。\n下記ページから抽選してください。\n\n{$url}\n\n有効期限: " . date('Y/m/d H:i', strtotime((string)$row['expires_at']));
            if ($line->pushText((string)$row['line_user_id'], $text)) {
                $sent++;
            }
        }

        return ['target' => count($rows), 'sent' => $sent];
    }

    public function statusForLineUser(string $lineUserId): array {
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '') {
            return ['ok' => false, 'message' => 'LINE認証が必要です。'];
        }

        $stmt = $this->pdo->prepare("
            SELECT ge.*, s.title AS class_title, s.class_date
            FROM gacha_entitlements ge
            LEFT JOIN class_schedules s ON s.id = ge.schedule_id" . $this->tenantJoinFilter('class_schedules', 's') . "
            WHERE ge.line_user_id = ?
              AND ge.expires_at >= NOW()
              " . $this->tenant->andWhere('gacha_entitlements', 'ge') . "
            ORDER BY ge.used_at IS NULL DESC, ge.id DESC
            LIMIT 1
        ");
        $stmt->execute(array_merge([$lineUserId], $this->tenant->params('gacha_entitlements')));
        $entitlement = $stmt->fetch();
        if (!$entitlement) {
            return ['ok' => true, 'has_entitlement' => false, 'message' => '現在利用できるガチャ参加権はありません。'];
        }

        $result = $this->resultByEntitlement((int)$entitlement['id']);
        return [
            'ok' => true,
            'has_entitlement' => true,
            'entitlement' => $entitlement,
            'result' => $result,
            'already_drawn' => (bool)$result,
        ];
    }

    public function draw(string $lineUserId): array {
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '') {
            return ['ok' => false, 'message' => 'LINE認証が必要です。'];
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM gacha_entitlements
                WHERE line_user_id = ?
                  AND status = 'active'
                  AND expires_at >= NOW()
                  " . $this->tenant->andWhere('gacha_entitlements') . "
                ORDER BY used_at IS NULL DESC, id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(array_merge([$lineUserId], $this->tenant->params('gacha_entitlements')));
            $entitlement = $stmt->fetch();
            if (!$entitlement) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => '利用できるガチャ参加権がありません。'];
            }

            $existing = $this->resultByEntitlement((int)$entitlement['id']);
            if ($existing) {
                $this->pdo->commit();
                return ['ok' => true, 'already_drawn' => true, 'result' => $existing];
            }

            $rarity = $this->pickRarity();
            $prize = $this->pickPrize((int)$entitlement['campaign_id'], (int)$rarity['id']);
            $expiresAt = null;
            $expiresDays = (int)($prize['expires_days'] ?? 0);
            if ($expiresDays > 0) {
                $expiresAt = date('Y-m-d H:i:s', time() + ($expiresDays * 86400));
            }

            $resultId = $this->insertTenantRecord('gacha_results', [
                'entitlement_id' => (int)$entitlement['id'],
                'campaign_id' => (int)$entitlement['campaign_id'],
                'attendance_id' => (int)$entitlement['attendance_id'],
                'schedule_id' => (int)$entitlement['schedule_id'],
                'user_id' => (int)$entitlement['user_id'],
                'line_user_id' => $lineUserId,
                'rarity_id' => (int)$rarity['id'],
                'prize_id' => (int)$prize['id'],
                'rarity_code' => (string)$rarity['code'],
                'rarity_name' => (string)$rarity['name'],
                'prize_name' => (string)$prize['name'],
                'reward_title' => (string)$prize['name'],
                'reward_detail' => (string)$prize['description'],
                'reward_expires_at' => $expiresAt,
                'drawn_at' => date('Y-m-d H:i:s'),
            ]);

            $this->pdo->prepare("UPDATE gacha_entitlements SET used_at = NOW(), status = 'used', updated_at = NOW() WHERE id = ?" . $this->tenant->andWhere('gacha_entitlements'))
                ->execute(array_merge([(int)$entitlement['id']], $this->tenant->params('gacha_entitlements')));

            $this->pdo->commit();
            return ['ok' => true, 'already_drawn' => false, 'result' => $this->resultById($resultId)];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['ok' => false, 'message' => '抽選に失敗しました。時間を置いて再度お試しください。'];
        }
    }

    public function markInterest(string $lineUserId, string $message = ''): array {
        $status = $this->statusForLineUser($lineUserId);
        if (empty($status['result']['id'])) {
            return ['ok' => false, 'message' => '先にガチャを実行してください。'];
        }

        $result = $status['result'];
        $this->insertTenantRecord('gacha_purchase_interests', [
            'result_id' => (int)$result['id'],
            'entitlement_id' => (int)$result['entitlement_id'],
            'user_id' => (int)$result['user_id'],
            'line_user_id' => $lineUserId,
            'status' => 'interested',
            'message' => trim($message),
        ], false, true);

        $this->notifyAdminInterest($result, $message);
        return ['ok' => true, 'message' => '購入希望を受け付けました。担当者からご案内します。'];
    }

    public function recentResults(): array {
        $stmt = $this->pdo->prepare("
            SELECT gr.*, u.display_name, s.title AS class_title, s.class_date
            FROM gacha_results gr
            LEFT JOIN users u ON u.id = gr.user_id" . $this->tenantJoinFilter('users', 'u') . "
            LEFT JOIN class_schedules s ON s.id = gr.schedule_id" . $this->tenantJoinFilter('class_schedules', 's') . "
            WHERE 1=1" . $this->tenant->andWhere('gacha_results', 'gr') . "
            ORDER BY gr.id DESC
            LIMIT 50
        ");
        $stmt->execute($this->tenant->params('gacha_results'));
        return $stmt->fetchAll();
    }

    public function recentInterests(): array {
        $stmt = $this->pdo->prepare("
            SELECT gi.*, gr.rarity_name, gr.prize_name, u.display_name
            FROM gacha_purchase_interests gi
            LEFT JOIN gacha_results gr ON gr.id = gi.result_id" . $this->tenantJoinFilter('gacha_results', 'gr') . "
            LEFT JOIN users u ON u.id = gi.user_id" . $this->tenantJoinFilter('users', 'u') . "
            WHERE 1=1" . $this->tenant->andWhere('gacha_purchase_interests', 'gi') . "
            ORDER BY gi.id DESC
            LIMIT 50
        ");
        $stmt->execute($this->tenant->params('gacha_purchase_interests'));
        return $stmt->fetchAll();
    }

    private function resultByEntitlement(int $entitlementId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT gr.*, r.video_url
            FROM gacha_results gr
            LEFT JOIN gacha_rarities r ON r.id = gr.rarity_id" . $this->tenantJoinFilter('gacha_rarities', 'r') . "
            WHERE gr.entitlement_id = ?
              " . $this->tenant->andWhere('gacha_results', 'gr') . "
            LIMIT 1
        ");
        $stmt->execute(array_merge([$entitlementId], $this->tenant->params('gacha_results')));
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function resultById(int $id): ?array {
        $stmt = $this->pdo->prepare("
            SELECT gr.*, r.video_url
            FROM gacha_results gr
            LEFT JOIN gacha_rarities r ON r.id = gr.rarity_id" . $this->tenantJoinFilter('gacha_rarities', 'r') . "
            WHERE gr.id = ?
              " . $this->tenant->andWhere('gacha_results', 'gr') . "
            LIMIT 1
        ");
        $stmt->execute(array_merge([$id], $this->tenant->params('gacha_results')));
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function pickRarity(): array {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM gacha_rarities
            WHERE weight > 0" . $this->tenant->andWhere('gacha_rarities') . "
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute($this->tenant->params('gacha_rarities'));
        $rows = $stmt->fetchAll();
        $total = array_sum(array_map(fn($r) => (int)$r['weight'], $rows));
        $hit = random_int(1, max(1, $total));
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int)$row['weight'];
            if ($hit <= $sum) {
                return $row;
            }
        }
        return $rows[0];
    }

    private function pickPrize(int $campaignId, int $rarityId): array {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM gacha_prizes
            WHERE rarity_id = ?
              AND is_active = 1
              AND (campaign_id IS NULL OR campaign_id = ?)
              " . $this->tenant->andWhere('gacha_prizes') . "
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute(array_merge([$rarityId, $campaignId], $this->tenant->params('gacha_prizes')));
        $prize = $stmt->fetch();
        if ($prize) {
            return $prize;
        }

        return [
            'id' => 0,
            'name' => '入陣記念特典',
            'description' => '購入希望を送信すると、担当者から詳細をご案内します。',
            'expires_days' => 14,
        ];
    }

    private function notifyAdminInterest(array $result, string $message): void {
        $adminLineUserId = trim((string)Settings::get('admin_line_user_id', ''));
        if ($adminLineUserId === '') {
            return;
        }

        $text = "ガチャ特典の購入希望が届きました。\n"
            . "ユーザーID: " . ($result['user_id'] ?? '-') . "\n"
            . "等級: " . ($result['rarity_name'] ?? '-') . "\n"
            . "特典: " . ($result['prize_name'] ?? '-') . "\n"
            . "メモ: " . (trim($message) !== '' ? trim($message) : '-');
        try {
            (new LineService())->pushText($adminLineUserId, $text);
        } catch (\Throwable $e) {
        }
    }

    private function absoluteUrl(string $path): string {
        $base = trim((string)Settings::get('public_base_url', ''));
        if ($base === '') {
            $base = trim((string)Settings::get('app_url', ''));
        }
        if ($base === '') {
            $base = trim((string)Settings::get('site_url', ''));
        }
        if ($base === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'school.sengoku-ai.com';
            $base = 'https://' . $host;
        }
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        $tenant = Settings::currentTenant();
        $key = trim((string)($tenant['tenant_key'] ?? ''));
        if ($key === '') {
            return $url;
        }
        return $url . (strpos($url, '?') !== false ? '&' : '?') . 'tenant=' . rawurlencode($key);
    }

    private function tenantJoinFilter(string $table, string $alias): string {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table)) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int)$this->tenant->tenantId();
    }

    private function ensureTenantColumns(): void {
        $tables = [
            'gacha_campaigns',
            'gacha_rarities',
            'gacha_prizes',
            'gacha_entitlements',
            'gacha_results',
            'gacha_purchase_interests',
        ];

        foreach ($tables as $table) {
            if (!$this->isSafeIdentifier($table) || !$this->tableExists($table)) {
                continue;
            }

            try {
                if (!$this->columnExists($table, 'tenant_id')) {
                    $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id");
                }

                $index = 'idx_' . $table . '_tenant_id';
                if (!$this->indexExists($table, $index)) {
                    $this->pdo->exec("ALTER TABLE {$table} ADD INDEX {$index} (tenant_id)");
                }

                if ($table === 'gacha_rarities') {
                    $this->ensureTenantRarityUniqueKey();
                }

                if ($this->tenant->isDefaultTenant()) {
                    $this->assignMissingTenant($table);
                }
            } catch (Throwable $e) {
            }
        }
    }

    private function ensureTenantRarityUniqueKey(): void {
        try {
            if ($this->indexExists('gacha_rarities', 'uq_gacha_rarity_code')) {
                $this->pdo->exec('ALTER TABLE gacha_rarities DROP INDEX uq_gacha_rarity_code');
            }
        } catch (Throwable $e) {
        }

        try {
            if (!$this->indexExists('gacha_rarities', 'uq_gacha_rarity_tenant_code')) {
                $this->pdo->exec('ALTER TABLE gacha_rarities ADD UNIQUE KEY uq_gacha_rarity_tenant_code (tenant_id, code)');
            }
        } catch (Throwable $e) {
        }
    }

    private function insertTenantRecord(
        string $table,
        array $data,
        bool $ignore = false,
        bool $withUpdatedAt = false
    ): int {
        if (!$this->isSafeIdentifier($table)) {
            throw new InvalidArgumentException('Invalid table name.');
        }
        [$columns, $values] = $this->tenant->assignInsert(
            $table,
            array_keys($data),
            array_values($data)
        );
        $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $timestampColumns = $withUpdatedAt ? ', created_at, updated_at' : ', created_at';
        $timestampValues = $withUpdatedAt ? ', NOW(), NOW()' : ', NOW()';
        $verb = $ignore ? 'INSERT IGNORE' : 'INSERT';
        $sql = $verb . ' INTO `' . $table . '` (' . implode(', ', $quotedColumns) . $timestampColumns
            . ') VALUES (' . $placeholders . $timestampValues . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount() > 0 ? (int)$this->pdo->lastInsertId() : 0;
    }

    private function assignMissingTenant(string $table): void {
        if (!$this->tenant->active() || !$this->tenant->hasTenantColumn($table) || !$this->isSafeIdentifier($table)) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE {$table} SET tenant_id = ? WHERE tenant_id IS NULL");
            $stmt->execute([(int)$this->tenant->tenantId()]);
        } catch (Throwable $e) {
        }
    }

    private function tableExists(string $table): bool {
        if (!$this->isSafeIdentifier($table)) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = ?
            ");
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool {
        if (!$this->isSafeIdentifier($table) || !$this->isSafeIdentifier($column)) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
            ");
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function indexExists(string $table, string $index): bool {
        if (!$this->isSafeIdentifier($table) || !$this->isSafeIdentifier($index)) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND index_name = ?
            ");
            $stmt->execute([$table, $index]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function isSafeIdentifier(string $identifier): bool {
        return (bool)preg_match('/^[A-Za-z0-9_]+$/', $identifier);
    }

    private function seedDefaults(): void {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM gacha_campaigns WHERE 1=1' . $this->tenant->andWhere('gacha_campaigns'));
        $stmt->execute($this->tenant->params('gacha_campaigns'));
        $count = (int)$stmt->fetchColumn();
        if ($count === 0) {
            $this->insertTenantRecord('gacha_campaigns', [
                'name' => '戦国クリエイター入陣ガチャ',
                'status' => 'active',
                'default_expires_days' => 14,
            ], false, true);
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM gacha_rarities WHERE 1=1' . $this->tenant->andWhere('gacha_rarities'));
        $stmt->execute($this->tenant->params('gacha_rarities'));
        $count = (int)$stmt->fetchColumn();
        if ($count === 0) {
            $rarityRows = [
                ['N', '足軽', 7000, 10, '#64748b'],
                ['R', '侍', 2200, 20, '#22c55e'],
                ['SR', '大名', 650, 30, '#6366f1'],
                ['SSR', '将軍', 140, 40, '#f59e0b'],
                ['LR', '天下人', 10, 50, '#ef4444'],
            ];
            foreach ($rarityRows as $rarityRow) {
                $this->insertTenantRecord('gacha_rarities', [
                    'code' => $rarityRow[0],
                    'name' => $rarityRow[1],
                    'weight' => $rarityRow[2],
                    'sort_order' => $rarityRow[3],
                    'color' => $rarityRow[4],
                ], true);
            }
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM gacha_prizes WHERE 1=1' . $this->tenant->andWhere('gacha_prizes'));
        $stmt->execute($this->tenant->params('gacha_prizes'));
        $count = (int)$stmt->fetchColumn();
        if ($count === 0) {
            $rarityStmt = $this->pdo->prepare('SELECT id, code FROM gacha_rarities WHERE 1=1' . $this->tenant->andWhere('gacha_rarities'));
            $rarityStmt->execute($this->tenant->params('gacha_rarities'));
            $rarities = $rarityStmt->fetchAll();
            $map = [];
            foreach ($rarities as $r) {
                $map[$r['code']] = (int)$r['id'];
            }
            $rows = [
                ['N', '入陣記念特典', '戦国クリエイター入陣記念の案内をお送りします。', 14],
                ['R', '武将応援特典', '関連商品の優待案内をお送りします。', 14],
                ['SR', '甲冑NFT優待', '甲冑NFTまたは関連企画の優待相談をご案内します。', 14],
                ['SSR', '評議員NFT相談特典', '評議員NFTや上位企画について個別にご案内します。', 14],
                ['LR', '特別入陣特典', '特別な購入特典について運営から直接ご案内します。', 7],
            ];
            $order = 10;
            foreach ($rows as $row) {
                if (!isset($map[$row[0]])) {
                    continue;
                }
                $this->insertTenantRecord('gacha_prizes', [
                    'rarity_id' => $map[$row[0]],
                    'name' => $row[1],
                    'description' => $row[2],
                    'reward_type' => 'purchase_interest',
                    'expires_days' => $row[3],
                    'is_active' => 1,
                    'sort_order' => $order,
                ]);
                $order += 10;
            }
        }
    }

    private function ensureTables(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS gacha_campaigns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                default_expires_days INT NOT NULL DEFAULT 14,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS gacha_rarities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL,
                name VARCHAR(191) NOT NULL,
                weight INT NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                video_url TEXT NULL,
                color VARCHAR(32) NULL,
                created_at DATETIME NULL,
                UNIQUE KEY uq_gacha_rarity_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS gacha_prizes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT NULL,
                rarity_id INT NOT NULL,
                name VARCHAR(191) NOT NULL,
                description TEXT NULL,
                product_name VARCHAR(191) NULL,
                reward_type VARCHAR(64) NOT NULL DEFAULT 'purchase_interest',
                discount_type VARCHAR(64) NULL,
                discount_value INT NULL,
                expires_days INT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                KEY idx_gacha_prizes_rarity (rarity_id),
                KEY idx_gacha_prizes_campaign (campaign_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS gacha_entitlements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT NOT NULL,
                attendance_id INT NOT NULL,
                schedule_id INT NOT NULL,
                user_id INT NOT NULL,
                line_user_id VARCHAR(191) NOT NULL,
                granted_by_admin_id INT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_gacha_entitlement_attendance_campaign (campaign_id, attendance_id),
                KEY idx_gacha_entitlements_line (line_user_id),
                KEY idx_gacha_entitlements_schedule (schedule_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS gacha_results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entitlement_id INT NOT NULL,
                campaign_id INT NOT NULL,
                attendance_id INT NOT NULL,
                schedule_id INT NOT NULL,
                user_id INT NOT NULL,
                line_user_id VARCHAR(191) NOT NULL,
                rarity_id INT NOT NULL,
                prize_id INT NOT NULL,
                rarity_code VARCHAR(32) NOT NULL,
                rarity_name VARCHAR(191) NOT NULL,
                prize_name VARCHAR(191) NOT NULL,
                reward_title VARCHAR(191) NULL,
                reward_detail TEXT NULL,
                reward_expires_at DATETIME NULL,
                drawn_at DATETIME NULL,
                confirmed_at DATETIME NULL,
                created_at DATETIME NULL,
                UNIQUE KEY uq_gacha_results_entitlement (entitlement_id),
                KEY idx_gacha_results_user (user_id),
                KEY idx_gacha_results_line (line_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS gacha_purchase_interests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                result_id INT NOT NULL,
                entitlement_id INT NOT NULL,
                user_id INT NOT NULL,
                line_user_id VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'interested',
                message TEXT NULL,
                notified_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                KEY idx_gacha_interests_status (status),
                KEY idx_gacha_interests_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
