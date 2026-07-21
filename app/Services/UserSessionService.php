<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';
require_once BASE_PATH . '/app/Services/SurveyDefinition.php';
require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class UserSessionService {
    private PDO $pdo;
    private TenantScopeService $tenant;
    private int $ttlMinutes;

    public function __construct() {
        $this->pdo = get_pdo();
        $this->tenant = new TenantScopeService($this->pdo);
        $this->ttlMinutes = max(5, (int)(Settings::get('survey_session_ttl_minutes', '30')));
        $this->ensureTable();
    }

    public function get(string $lineUserId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM user_sessions
            WHERE line_user_id = ? AND expires_at > NOW()" . $this->tenant->andWhere('user_sessions') . "
            LIMIT 1
        ");
        $stmt->execute(array_merge([$lineUserId], $this->tenant->params('user_sessions')));
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['survey_data'] = json_decode((string)($row['survey_data'] ?? '{}'), true) ?: [];
        return $row;
    }

    public function start(string $lineUserId): void {
        $this->upsert($lineUserId, SurveyDefinition::STEP_STYLE, []);
    }

    public function advance(string $lineUserId, string $nextStep, array $data): void {
        $this->upsert($lineUserId, $nextStep, $data);
    }

    public function clear(string $lineUserId): void {
        $this->pdo->prepare("DELETE FROM user_sessions WHERE line_user_id = ?" . $this->tenant->andWhere('user_sessions'))
            ->execute(array_merge([$lineUserId], $this->tenant->params('user_sessions')));
    }

    public function touch(string $lineUserId): void {
        $expires = date('Y-m-d H:i:s', strtotime("+{$this->ttlMinutes} minutes"));
        $this->pdo->prepare("UPDATE user_sessions SET expires_at = ? WHERE line_user_id = ?" . $this->tenant->andWhere('user_sessions'))
            ->execute(array_merge([$expires, $lineUserId], $this->tenant->params('user_sessions')));
    }

    public function cleanup(): void {
        $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE expires_at <= NOW()" . $this->tenant->andWhere('user_sessions'));
        $stmt->execute($this->tenant->params('user_sessions'));
    }

    private function upsert(string $lineUserId, string $step, array $data): void {
        $expires = date('Y-m-d H:i:s', strtotime("+{$this->ttlMinutes} minutes"));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        [$columns, $values] = $this->tenant->assignInsert(
            'user_sessions',
            ['line_user_id', 'step', 'survey_data', 'expires_at'],
            [$lineUserId, $step, $json, $expires]
        );
        $columnSql = implode(', ', array_map(static fn($column) => '`' . $column . '`', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $this->pdo->prepare("
            INSERT INTO user_sessions ({$columnSql}, created_at, updated_at)
            VALUES ({$placeholders}, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                step = VALUES(step),
                survey_data = VALUES(survey_data),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
        ")->execute($values);
    }

    private function ensureTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS user_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id BIGINT UNSIGNED NULL,
                line_user_id VARCHAR(255) NOT NULL,
                step VARCHAR(50) NOT NULL DEFAULT 'idle',
                survey_data JSON,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_sessions_tenant_line (tenant_id, line_user_id),
                KEY idx_user_sessions_tenant (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM user_sessions")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tenant_id', $columns, true)) {
                $this->pdo->exec('ALTER TABLE user_sessions ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id');
            }
            $indexes = $this->pdo->query("SHOW INDEX FROM user_sessions")->fetchAll(PDO::FETCH_ASSOC);
            $legacyUnique = [];
            $hasComposite = false;
            foreach ($indexes as $index) {
                $name = (string)($index['Key_name'] ?? '');
                if ((int)($index['Non_unique'] ?? 1) !== 0 || $name === 'PRIMARY') {
                    continue;
                }
                $legacyUnique[$name][(int)($index['Seq_in_index'] ?? 0)] = (string)($index['Column_name'] ?? '');
            }
            foreach ($legacyUnique as $name => $parts) {
                ksort($parts);
                $indexedColumns = array_values($parts);
                if ($indexedColumns === ['tenant_id', 'line_user_id']) {
                    $hasComposite = true;
                } elseif ($indexedColumns === ['line_user_id']) {
                    $this->pdo->exec('ALTER TABLE user_sessions DROP INDEX `' . str_replace('`', '``', $name) . '`');
                }
            }
            if (!$hasComposite) {
                $this->pdo->exec(
                    'ALTER TABLE user_sessions ADD UNIQUE KEY uniq_sessions_tenant_line (tenant_id, line_user_id)'
                );
            }
        } catch (Throwable $e) {
            // TenantDataService performs the same migration from the owner dashboard.
        }
    }
}
