<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @global-resource reason=T09 media_resources and media_change_logs are supplier-scoped tables
// without company_id; company-level visibility is enforced in the service/API layer.
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $mediaResources = $prefix . 'media_resources';
        $mediaChangeLogs = $prefix . 'media_change_logs';
        foreach ([$mediaResources, $mediaChangeLogs] as $table) {
            if ($this->tableExists($pdo, $table)) {
                throw new RuntimeException('T09 table exists outside migration history: ' . $table);
            }
        }

        $created = [];
        try {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($mediaResources) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`supplier_id` BIGINT UNSIGNED NOT NULL,'
                . '`external_id` VARCHAR(191) NOT NULL,'
                . '`channel_id` BIGINT UNSIGNED DEFAULT NULL,'
                . '`name` VARCHAR(500) NOT NULL,'
                . '`cost_price` DECIMAL(14,6) NOT NULL DEFAULT 0.000000,'
                . '`cost_currency` VARCHAR(3) NOT NULL DEFAULT \'CNY\','
                . '`status` VARCHAR(20) NOT NULL DEFAULT \'active\','
                . '`supplier_raw_status` VARCHAR(100) DEFAULT NULL,'
                . '`enterprise_status` VARCHAR(20) DEFAULT NULL,'
                . '`enterprise_status_set_by` BIGINT UNSIGNED DEFAULT NULL,'
                . '`enterprise_status_set_at` DATETIME DEFAULT NULL,'
                . '`enterprise_status_reason` VARCHAR(500) DEFAULT NULL,'
                . '`attributes_json` JSON DEFAULT NULL,'
                . '`last_synced_at` DATETIME DEFAULT NULL,'
                . '`last_sync_error` VARCHAR(500) DEFAULT NULL,'
                . '`created_at` DATETIME NOT NULL,'
                . '`updated_at` DATETIME NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_media_supplier_external` (`supplier_id`,`external_id`),'
                . 'KEY `idx_media_channel_status` (`channel_id`,`status`),'
                . 'KEY `idx_media_last_synced` (`last_synced_at`),'
                . 'KEY `idx_media_supplier_status` (`supplier_id`,`status`),'
                . 'KEY `idx_media_name` (`name`(191))'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $mediaResources;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($mediaChangeLogs) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`media_resource_id` BIGINT UNSIGNED NOT NULL,'
                . '`change_type` VARCHAR(50) NOT NULL,'
                . '`field_name` VARCHAR(100) DEFAULT NULL,'
                . '`old_value` VARCHAR(1000) DEFAULT NULL,'
                . '`new_value` VARCHAR(1000) DEFAULT NULL,'
                . '`sync_task_id` VARCHAR(64) DEFAULT NULL,'
                . '`created_at` DATETIME NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_mcl_media` (`media_resource_id`),'
                . 'KEY `idx_mcl_type` (`change_type`),'
                . 'KEY `idx_mcl_task` (`sync_task_id`),'
                . 'KEY `idx_mcl_created` (`created_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $mediaChangeLogs;

            $suppliers = $prefix . 'suppliers';
            $pdo->exec(
                'INSERT INTO ' . $this->quote($suppliers)
                . ' (`code`,`display_name`,`status`,`created_at`,`updated_at`) VALUES '
                . "('media_market','Media Marketplace','normal',NOW(),NOW())"
                . ' ON DUPLICATE KEY UPDATE `updated_at`=NOW()'
            );
        } catch (Throwable $exception) {
            foreach (array_reverse($created) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($table));
            }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'media_change_logs'));
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'media_resources'));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
