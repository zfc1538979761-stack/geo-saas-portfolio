<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @global-resource reason=T17 operations/alert/backup records are internal global tables without company_id.
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $tables = [
            $prefix . 'geo_alert_events',
            $prefix . 'geo_health_snapshots',
            $prefix . 'geo_backup_records',
        ];
        foreach ($tables as $table) {
            if ($this->tableExists($pdo, $table)) {
                throw new RuntimeException('T17 operations table exists outside migration history: ' . $table);
            }
        }

        $created = [];
        try {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($tables[0]) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`rule_key` VARCHAR(64) NOT NULL,'
                . '`severity` VARCHAR(20) NOT NULL,'
                . '`source` VARCHAR(50) NOT NULL,'
                . '`title` VARCHAR(200) NOT NULL,'
                . '`message` VARCHAR(500) NOT NULL,'
                . '`fingerprint` VARCHAR(64) NOT NULL,'
                . '`metadata_json` JSON NULL,'
                . '`status` VARCHAR(20) NOT NULL DEFAULT \'open\','
                . '`event_count` INT UNSIGNED NOT NULL DEFAULT 1,'
                . '`first_seen_at` DATETIME NOT NULL,'
                . '`last_seen_at` DATETIME NOT NULL,'
                . '`resolved_at` DATETIME NULL,'
                . '`acknowledged_at` DATETIME NULL,'
                . '`acknowledged_by` BIGINT UNSIGNED NULL,'
                . '`ack_reason` VARCHAR(500) NULL,'
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_alert_rule_fingerprint` (`rule_key`,`fingerprint`),'
                . 'KEY `idx_alert_status_seen` (`status`,`severity`,`last_seen_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $tables[0];

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($tables[1]) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`checked_at` DATETIME NOT NULL,'
                . '`overall_status` VARCHAR(20) NOT NULL,'
                . '`database_status` VARCHAR(20) NOT NULL,'
                . '`redis_status` VARCHAR(20) NOT NULL,'
                . '`ai_status` VARCHAR(20) NOT NULL,'
                . '`supplier_status` VARCHAR(20) NOT NULL,'
                . '`queue_backlog` INT UNSIGNED NOT NULL DEFAULT 0,'
                . '`queue_failed` INT UNSIGNED NOT NULL DEFAULT 0,'
                . '`stale_orders` INT UNSIGNED NOT NULL DEFAULT 0,'
                . '`financial_anomalies` INT UNSIGNED NOT NULL DEFAULT 0,'
                . '`metrics_json` JSON NULL,'
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_health_checked` (`checked_at`,`overall_status`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $tables[1];

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($tables[2]) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`operation` VARCHAR(20) NOT NULL,'
                . '`status` VARCHAR(20) NOT NULL,'
                . '`source_database` VARCHAR(191) NULL,'
                . '`target_database` VARCHAR(191) NULL,'
                . '`file_path` VARCHAR(500) NULL,'
                . '`checksum` VARCHAR(64) NULL,'
                . '`row_counts_json` JSON NULL,'
                . '`error_message` VARCHAR(500) NULL,'
                . '`started_at` DATETIME NOT NULL,'
                . '`completed_at` DATETIME NULL,'
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_backup_operation_status` (`operation`,`status`,`created_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $tables[2];
        } catch (Throwable $exception) {
            foreach (array_reverse($created) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($table));
            }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        foreach (['geo_backup_records', 'geo_health_snapshots', 'geo_alert_events'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . $table));
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
