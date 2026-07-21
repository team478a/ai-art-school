<?php
require_once BASE_PATH . '/config/database.php';

class TenantBackupService {
    private PDO $pdo;
    private string $backupRoot;

    private array $tenantTables = [
        'admin_users',
        'users',
        'class_schedules',
        'class_attendances',
        'user_sessions',
        'image_requests',
        'prompts',
        'generated_images',
        'job_queue',
        'system_logs',
        'payment_transactions',
        'payment_logs',
        'reservation_event_logs',
        'ticket_logs',
        'class_waitlists',
        'image_request_usage_overrides',
        'subscriptions',
        'user_tickets',
        'audit_logs',
        'operation_logs',
        'login_logs',
        'admin_login_logs',
        'class_notification_logs',
        'class_followup_logs',
        'gacha_campaigns',
        'gacha_rarities',
        'gacha_prizes',
        'gacha_entitlements',
        'gacha_entries',
        'gacha_results',
        'gacha_purchase_interests',
    ];

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?: get_pdo();
        $this->backupRoot = STORAGE_PATH . '/tenant_backups';
    }

    public function root(): string {
        return $this->backupRoot;
    }

    public function list(array $tenant): array {
        $tenantKey = $this->safeTenantKey((string)($tenant['tenant_key'] ?? 'tenant'));
        $dir = $this->backupRoot . '/' . $tenantKey;
        if (!is_dir($dir)) {
            return [];
        }

        $rows = [];
        foreach (glob($dir . '/*.zip') ?: [] as $file) {
            $id = basename($file, '.zip');
            $manifest = $this->readManifestFromZip($file);
            $rows[] = [
                'id' => $id,
                'file' => $file,
                'created_at' => $manifest['created_at'] ?? date('Y-m-d H:i:s', (int)filemtime($file)),
                'size' => filesize($file) ?: 0,
                'tables' => (int)($manifest['tables'] ?? 0),
                'rows' => (int)($manifest['rows'] ?? 0),
                'assets' => (int)($manifest['assets'] ?? 0),
                'version' => $manifest['app_version'] ?? '',
            ];
        }

        usort($rows, static fn($a, $b) => strcmp((string)$b['id'], (string)$a['id']));
        return $rows;
    }

    public function inspectLatest(array $tenant): array {
        $rows = $this->list($tenant);
        if (!$rows) {
            return [
                'status' => 'warning',
                'label' => 'バックアップ未作成',
                'details' => ['運用開始前にクライアント単位のバックアップを1件作成してください。'],
                'backup' => null,
            ];
        }

        $latest = $rows[0];
        $path = (string)($latest['file'] ?? '');
        $details = [];
        if ($path === '' || !is_file($path) || (int)($latest['size'] ?? 0) <= 0) {
            return [
                'status' => 'ng',
                'label' => 'バックアップファイル異常',
                'details' => ['最新バックアップが見つからないか、ファイルサイズが0です。'],
                'backup' => $latest,
            ];
        }
        if (!class_exists('ZipArchive')) {
            return [
                'status' => 'warning',
                'label' => 'ZIP検査不可',
                'details' => ['ZipArchiveが利用できないため、内容の整合性を確認できません。'],
                'backup' => $latest,
            ];
        }

        $manifest = $this->readManifestFromZip($path);
        if (($manifest['type'] ?? '') !== 'aiart_tenant_backup') {
            return [
                'status' => 'ng',
                'label' => 'バックアップ形式異常',
                'details' => ['manifest.jsonがないか、バックアップ形式が一致しません。'],
                'backup' => $latest,
            ];
        }
        if ((string)($manifest['tenant_key'] ?? '') !== (string)($tenant['tenant_key'] ?? '')) {
            return [
                'status' => 'ng',
                'label' => 'クライアント不一致',
                'details' => ['最新バックアップは現在のクライアント用ではありません。'],
                'backup' => $latest,
            ];
        }

        $createdAt = strtotime((string)($manifest['created_at'] ?? $latest['created_at'] ?? '')) ?: 0;
        $ageDays = $createdAt > 0 ? (int)floor((time() - $createdAt) / 86400) : 999;
        $details[] = '作成日時: ' . (string)($manifest['created_at'] ?? $latest['created_at'] ?? '-');
        $details[] = 'データ: ' . (int)($manifest['rows'] ?? 0) . '件 / 画像等: ' . (int)($manifest['assets'] ?? 0) . '件';
        if ($ageDays > 7) {
            $details[] = '最終バックアップから' . $ageDays . '日経過しています。';
            return ['status' => 'warning', 'label' => 'バックアップが古い', 'details' => $details, 'backup' => $latest];
        }
        return ['status' => 'ok', 'label' => '最新バックアップ正常', 'details' => $details, 'backup' => $latest];
    }

    public function create(array $tenant): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive is not available.');
        }

        $tenantId = (int)($tenant['id'] ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('Tenant is invalid.');
        }

        $tenantKey = $this->safeTenantKey((string)($tenant['tenant_key'] ?? 'tenant'));
        $backupId = date('Ymd_His') . '_' . $tenantKey;
        $tenantDir = $this->backupRoot . '/' . $tenantKey;
        $workDir = $tenantDir . '/' . $backupId;
        $zipPath = $tenantDir . '/' . $backupId . '.zip';

        $this->ensureDir($workDir . '/database');
        $this->ensureDir($workDir . '/assets');

        $rowCount = 0;
        $tableCount = 0;
        $assetCount = 0;
        $assetSeen = [];

        $tenantRow = $tenant;
        $this->writeJson($workDir . '/database/tenant.json', $tenantRow);

        $settings = $this->fetchRows('tenant_settings', 'tenant_id', $tenantId);
        $rowCount += count($settings);
        $tableCount += 1;
        $this->writeJson($workDir . '/database/tenant_settings.json', $settings);

        foreach ($this->tenantTables as $table) {
            if (!$this->hasTenantColumn($table)) {
                continue;
            }
            $rows = $this->fetchRows($table, 'tenant_id', $tenantId);
            $rowCount += count($rows);
            $tableCount += 1;
            $this->writeJson($workDir . '/database/' . $table . '.json', $rows);
            foreach ($rows as $row) {
                foreach ($this->extractAssetPaths($row) as $assetPath) {
                    if (isset($assetSeen[$assetPath])) {
                        continue;
                    }
                    $assetSeen[$assetPath] = true;
                    if ($this->copyAssetToBackup($assetPath, $workDir . '/assets')) {
                        $assetCount++;
                    }
                }
            }
        }

        $manifest = [
            'type' => 'aiart_tenant_backup',
            'backup_id' => $backupId,
            'created_at' => date('Y-m-d H:i:s'),
            'app_version' => $this->appVersion(),
            'tenant_id' => $tenantId,
            'tenant_key' => (string)($tenant['tenant_key'] ?? ''),
            'tenant_name' => (string)($tenant['name'] ?? ''),
            'tables' => $tableCount,
            'rows' => $rowCount,
            'assets' => $assetCount,
        ];
        $this->writeJson($workDir . '/manifest.json', $manifest);

        $this->zipDirectory($workDir, $zipPath);
        $this->removeTree($workDir);

        return [
            'id' => $backupId,
            'path' => $zipPath,
            'manifest' => $manifest,
        ];
    }

    public function restore(array $tenant, string $backupId): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive is not available.');
        }

        $tenantId = (int)($tenant['id'] ?? 0);
        $tenantKey = $this->safeTenantKey((string)($tenant['tenant_key'] ?? 'tenant'));
        $backupId = preg_replace('/[^A-Za-z0-9_-]/', '', $backupId);
        $zipPath = $this->backupRoot . '/' . $tenantKey . '/' . $backupId . '.zip';
        if ($backupId === '' || !is_file($zipPath)) {
            throw new RuntimeException('Backup file was not found.');
        }

        $workDir = $this->backupRoot . '/' . $tenantKey . '/restore_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $this->ensureDir($workDir);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Backup ZIP could not be opened.');
        }
        $zip->extractTo($workDir);
        $zip->close();

        $manifest = $this->readJson($workDir . '/manifest.json');
        if (($manifest['type'] ?? '') !== 'aiart_tenant_backup') {
            $this->removeTree($workDir);
            throw new RuntimeException('This is not a tenant backup.');
        }
        if ((string)($manifest['tenant_key'] ?? '') !== (string)($tenant['tenant_key'] ?? '')) {
            $this->removeTree($workDir);
            throw new RuntimeException('Tenant key does not match this backup.');
        }

        $restoredRows = 0;
        $restoredTables = 0;
        $restoredAssets = 0;

        $this->pdo->beginTransaction();
        try {
            $settings = $this->readJson($workDir . '/database/tenant_settings.json');
            if (is_array($settings)) {
                $this->pdo->prepare('DELETE FROM tenant_settings WHERE tenant_id = ?')->execute([$tenantId]);
                $restoredRows += $this->insertRows('tenant_settings', $settings, $tenantId);
                $restoredTables++;
            }

            foreach ($this->tenantTables as $table) {
                $file = $workDir . '/database/' . $table . '.json';
                if (!is_file($file) || !$this->hasTenantColumn($table)) {
                    continue;
                }
                $rows = $this->readJson($file);
                if (!is_array($rows)) {
                    continue;
                }
                $this->pdo->prepare('DELETE FROM ' . $this->quoteIdentifier($table) . ' WHERE tenant_id = ?')->execute([$tenantId]);
                $restoredRows += $this->insertRows($table, $rows, $tenantId);
                $restoredTables++;
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->removeTree($workDir);
            throw $e;
        }

        $assetDir = $workDir . '/assets';
        if (is_dir($assetDir)) {
            $restoredAssets = $this->restoreAssets($assetDir);
        }
        $this->removeTree($workDir);

        return [
            'tables' => $restoredTables,
            'rows' => $restoredRows,
            'assets' => $restoredAssets,
        ];
    }

    public function zipPath(array $tenant, string $backupId): ?string {
        $tenantKey = $this->safeTenantKey((string)($tenant['tenant_key'] ?? 'tenant'));
        $backupId = preg_replace('/[^A-Za-z0-9_-]/', '', $backupId);
        $path = $this->backupRoot . '/' . $tenantKey . '/' . $backupId . '.zip';
        return is_file($path) ? $path : null;
    }

    private function fetchRows(string $table, string $column, int $tenantId): array {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . $this->quoteIdentifier($column) . ' = ?'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function insertRows(string $table, array $rows, int $tenantId): int {
        if (!$this->tableExists($table) || empty($rows)) {
            return 0;
        }

        $columns = $this->columns($table);
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['tenant_id'] = $tenantId;
            $insert = [];
            foreach ($row as $key => $value) {
                if (in_array($key, $columns, true)) {
                    $insert[$key] = $value;
                }
            }
            if (empty($insert)) {
                continue;
            }
            $names = array_keys($insert);
            $sql = 'INSERT INTO ' . $this->quoteIdentifier($table) .
                ' (' . implode(',', array_map([$this, 'quoteIdentifier'], $names)) . ')' .
                ' VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($insert));
            $count++;
        }
        return $count;
    }

    private function columns(string $table): array {
        $stmt = $this->pdo->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $stmt->execute([$table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function hasTenantColumn(string $table): bool {
        return $this->tableExists($table) && $this->columnExists($table, 'tenant_id');
    }

    private function tableExists(string $table): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function extractAssetPaths(array $row): array {
        $paths = [];
        foreach ($row as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (preg_match_all('#(?:^|["\']|https?://[^/]+/)(uploads/[A-Za-z0-9_./% -]+)#', $value, $matches)) {
                foreach ($matches[1] as $path) {
                    $paths[] = rawurldecode(trim($path, " \t\n\r\0\x0B\"'"));
                }
            }
        }
        return array_values(array_unique($paths));
    }

    private function copyAssetToBackup(string $relativePath, string $assetRoot): bool {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (strpos($relativePath, '..') !== false || strpos($relativePath, 'uploads/') !== 0) {
            return false;
        }
        $source = BASE_PATH . '/' . $relativePath;
        if (!is_file($source)) {
            return false;
        }
        $target = rtrim($assetRoot, '/\\') . '/' . $relativePath;
        $this->ensureDir(dirname($target));
        return copy($source, $target);
    }

    private function restoreAssets(string $assetDir): int {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($assetDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($assetDir) + 1));
            if (strpos($relative, '..') !== false || strpos($relative, 'uploads/') !== 0) {
                continue;
            }
            $target = BASE_PATH . '/' . $relative;
            $this->ensureDir(dirname($target));
            if (copy($file->getPathname(), $target)) {
                $count++;
            }
        }
        return $count;
    }

    private function zipDirectory(string $sourceDir, string $zipPath): void {
        $this->ensureDir(dirname($zipPath));
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Backup ZIP could not be created.');
        }
        $sourceDir = rtrim($sourceDir, '/\\');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $local = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir) + 1));
            $zip->addFile($file->getPathname(), $local);
        }
        $zip->close();
    }

    private function readManifestFromZip(string $zipPath): array {
        if (!class_exists('ZipArchive')) {
            return [];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }
        $json = $zip->getFromName('manifest.json');
        $zip->close();
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function readJson(string $path) {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function writeJson(string $path, $data): void {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function ensureDir(string $dir): void {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Directory could not be created: ' . $dir);
        }
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function quoteIdentifier(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function safeTenantKey(string $key): string {
        $key = preg_replace('/[^A-Za-z0-9_-]/', '-', $key);
        return trim((string)$key, '-_') ?: 'tenant';
    }

    private function appVersion(): string {
        $path = BASE_PATH . '/VERSION';
        return is_file($path) ? trim((string)file_get_contents($path)) : '';
    }
}
