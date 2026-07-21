<?php

require_once BASE_PATH . '/app/Services/TenantScopeService.php';

class GenerationTestAccessService {
    private PDO $pdo;
    private TenantScopeService $tenant;
    private static bool $schemaEnsured = false;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->tenant = new TenantScopeService($pdo);
        $this->ensureColumns();
    }

    public function isEnabledForLineUserId(string $lineUserId): bool {
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '') {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM users
                 WHERE line_user_id = ?
                   AND generation_test_enabled = 1
                   AND (generation_test_until IS NULL OR generation_test_until >= NOW())
                   AND COALESCE(status, 'active') = 'active'"
                . $this->tenant->andWhere('users') . " LIMIT 1"
            );
            $stmt->execute(array_merge([$lineUserId], $this->tenant->params('users')));
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function isEnabledForUserId(int $userId): bool {
        if ($userId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM users
                 WHERE id = ?
                   AND generation_test_enabled = 1
                   AND (generation_test_until IS NULL OR generation_test_until >= NOW())
                   AND COALESCE(status, 'active') = 'active'"
                . $this->tenant->andWhere('users') . " LIMIT 1"
            );
            $stmt->execute(array_merge([$userId], $this->tenant->params('users')));
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function saveForUser(int $userId, bool $enabled, ?string $until, string $memo): bool {
        $stmt = $this->pdo->prepare(
            "UPDATE users
             SET generation_test_enabled = ?,
                 generation_test_until = ?,
                 generation_test_memo = ?,
                 updated_at = NOW()
             WHERE id = ?" . $this->tenant->andWhere('users')
        );
        return $stmt->execute(array_merge([
            $enabled ? 1 : 0,
            $enabled ? $until : null,
            $memo,
            $userId,
        ], $this->tenant->params('users')));
    }

    private function ensureColumns(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $columns = [
            'generation_test_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
            'generation_test_until' => "DATETIME NULL",
            'generation_test_memo' => "VARCHAR(255) NULL",
        ];
        foreach ($columns as $name => $definition) {
            try {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN {$name} {$definition}");
            } catch (Throwable $e) {
                // Existing columns are expected after the first installation.
            }
        }
        self::$schemaEnsured = true;
    }
}
