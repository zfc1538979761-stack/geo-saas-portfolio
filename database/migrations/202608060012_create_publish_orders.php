<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $orders = $prefix . 'publish_orders';
        $items  = $prefix . 'publish_order_items';
        $logs   = $prefix . 'order_status_logs';

        foreach ([$orders, $items, $logs] as $table) {
            if ($this->tableExists($pdo, $table)) {
                throw new RuntimeException('T11 table exists outside migration history: ' . $table);
            }
        }

        $created = [];
        try {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($orders) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`order_no` VARCHAR(50) NOT NULL,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`article_id` BIGINT UNSIGNED NOT NULL,'
                . '`article_version_id` BIGINT UNSIGNED NOT NULL,'
                . '`status` VARCHAR(30) NOT NULL DEFAULT \'funds_frozen\','
                . '`total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`frozen_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`idempotency_key` VARCHAR(191) NOT NULL,'
                . '`request_fingerprint` VARCHAR(64) NOT NULL,'
                . '`created_by` BIGINT UNSIGNED NOT NULL,'
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_order_no` (`order_no`),'
                . 'UNIQUE KEY `uq_company_idempotency` (`company_id`,`idempotency_key`),'
                . 'KEY `idx_company_status_id` (`company_id`,`status`,`id`),'
                . 'KEY `idx_company_article` (`company_id`,`article_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $orders;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($items) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`order_id` BIGINT UNSIGNED NOT NULL,'
                . '`media_id` BIGINT UNSIGNED NOT NULL,'
                . '`supplier_id` BIGINT UNSIGNED NOT NULL,'
                . '`channel_id` BIGINT UNSIGNED NOT NULL,'
                . '`cost_snapshot` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`sale_snapshot` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`status` VARCHAR(30) NOT NULL DEFAULT \'funds_frozen\','
                . '`supplier_order_no` VARCHAR(100) DEFAULT NULL,'
                . '`published_url` VARCHAR(2048) DEFAULT NULL,'
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_order_media` (`order_id`,`media_id`),'
                . 'KEY `idx_company_order` (`company_id`,`order_id`),'
                . 'KEY `idx_media_status` (`media_id`,`status`),'
                . 'KEY `idx_supplier_order_no` (`supplier_id`,`supplier_order_no`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $items;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($logs) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`order_id` BIGINT UNSIGNED NOT NULL,'
                . '`order_item_id` BIGINT UNSIGNED DEFAULT NULL,'
                . '`from_status` VARCHAR(30) DEFAULT NULL,'
                . '`to_status` VARCHAR(30) NOT NULL,'
                . '`source` VARCHAR(30) NOT NULL DEFAULT \'system\','
                . '`event_key` VARCHAR(191) NOT NULL,'
                . '`reason` VARCHAR(500) DEFAULT NULL,'
                . '`metadata_json` JSON DEFAULT NULL,'
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_company_event` (`company_id`,`event_key`),'
                . 'KEY `idx_order_history` (`company_id`,`order_id`,`id`),'
                . 'KEY `idx_item_history` (`company_id`,`order_item_id`,`id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $logs;

        } catch (Throwable $exception) {
            foreach (array_reverse($created) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($table));
            }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'order_status_logs'));
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'publish_order_items'));
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'publish_orders'));
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
