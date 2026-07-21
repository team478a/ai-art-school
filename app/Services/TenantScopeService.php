<?php
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/settings.php';

class TenantScopeService {
    private PDO $pdo;
    private ?int $tenantId;
    private bool $defaultTenant = true;
    private array $columnCache = [];

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?: get_pdo();
        $tenant = class_exists('Settings') ? Settings::currentTenant() : null;
        $this->tenantId = $tenant && !empty($tenant['id']) ? (int)$tenant['id'] : null;
        $this->defaultTenant = !$tenant || !empty($tenant['is_default']);
    }

    public function tenantId(): ?int {
        return $this->tenantId;
    }

    public function active(): bool {
        return !empty($this->tenantId);
    }

    public function isDefaultTenant(): bool {
        return $this->defaultTenant;
    }

    public function where(string $table, string $alias = ''): string {
        if (!$this->active()) {
            return '';
        }

        if (!$this->hasTenantColumn($table)) {
            return $this->defaultTenant ? '' : '1 = 0';
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        return $prefix . 'tenant_id = ?';
    }

    public function andWhere(string $table, string $alias = ''): string {
        $where = $this->where($table, $alias);
        return $where !== '' ? ' AND ' . $where : '';
    }

    public function params(string $table): array {
        if (!$this->active() || !$this->hasTenantColumn($table)) {
            return [];
        }
        return [(int)$this->tenantId];
    }

    public function assignInsert(string $table, array $columns, array $values): array {
        if ($this->active() && !$this->hasTenantColumn($table) && !$this->defaultTenant) {
            throw new RuntimeException('テナント分離カラムがありません: ' . $table);
        }

        if ($this->active() && $this->hasTenantColumn($table) && !in_array('tenant_id', $columns, true)) {
            array_unshift($columns, 'tenant_id');
            array_unshift($values, (int)$this->tenantId);
        }
        return [$columns, $values];
    }

    public function hasTenantColumn(string $table): bool {
        if (array_key_exists($table, $this->columnCache)) {
            return $this->columnCache[$table];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = 'tenant_id'
            ");
            $stmt->execute([$table]);
            $this->columnCache[$table] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $this->columnCache[$table] = false;
        }

        return $this->columnCache[$table];
    }
}
