<?php

require_once BASE_PATH . '/config/database.php';

class IntegrationSchemaService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: get_pdo();
    }

    public function ensure(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS integration_user_mappings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NULL,
            local_user_id BIGINT UNSIGNED NOT NULL,
            ai_art_member_id VARCHAR(191) NOT NULL,
            common_user_id VARCHAR(191) NULL,
            line_user_id VARCHAR(191) NOT NULL,
            project_key VARCHAR(100) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            response_json LONGTEXT NULL,
            last_error TEXT NULL,
            last_attempt_at DATETIME NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_integration_user_local (tenant_id, local_user_id),
            UNIQUE KEY uq_integration_ai_art_member (ai_art_member_id),
            KEY idx_integration_common_user (tenant_id, common_user_id),
            KEY idx_integration_line_user (tenant_id, line_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS integration_referral_mappings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NULL,
            local_user_id BIGINT UNSIGNED NOT NULL,
            referral_token_hash CHAR(64) NOT NULL,
            registration_referrer_agent_code VARCHAR(191) NULL,
            sales_agent_code VARCHAR(191) NULL,
            assigned_agent_code VARCHAR(191) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            confirmed_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_integration_referral_local (tenant_id, local_user_id),
            KEY idx_integration_referral_status (tenant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS integration_outbox_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NULL,
            event_id VARCHAR(100) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            aggregate_type VARCHAR(100) NOT NULL,
            aggregate_id VARCHAR(191) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_integration_event_id (event_id),
            KEY idx_integration_outbox_next (tenant_id, status, available_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS integration_inbox_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NULL,
            tenant_key VARCHAR(100) NOT NULL DEFAULT 'default',
            event_id VARCHAR(100) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            source VARCHAR(100) NOT NULL DEFAULT 'shopping',
            payload_json LONGTEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'received',
            last_error TEXT NULL,
            received_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_integration_inbox_event (tenant_key, event_id),
            KEY idx_integration_inbox_status (tenant_id, status, received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS integration_payment_projections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NULL,
            tenant_key VARCHAR(100) NOT NULL DEFAULT 'default',
            order_id VARCHAR(191) NOT NULL,
            common_user_id VARCHAR(191) NULL,
            status VARCHAR(30) NOT NULL,
            amount BIGINT NULL,
            currency VARCHAR(10) NULL,
            payload_json LONGTEXT NOT NULL,
            paid_at DATETIME NULL,
            refunded_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_integration_payment_order (tenant_key, order_id),
            KEY idx_integration_payment_user (tenant_id, common_user_id),
            KEY idx_integration_payment_status (tenant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS integration_entitlement_projections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NULL,
            tenant_key VARCHAR(100) NOT NULL DEFAULT 'default',
            entitlement_id VARCHAR(191) NOT NULL,
            local_user_id BIGINT UNSIGNED NULL,
            common_user_id VARCHAR(191) NULL,
            entitlement_type VARCHAR(50) NOT NULL,
            product_code VARCHAR(191) NULL,
            quantity INT NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL,
            valid_from DATETIME NULL,
            valid_until DATETIME NULL,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_integration_entitlement (tenant_key, entitlement_id),
            KEY idx_integration_entitlement_user (tenant_id, local_user_id, status),
            KEY idx_integration_entitlement_common (tenant_id, common_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
