<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'webhook_events';

        if ($this->tableExists($pdo, $table)) {
            throw new RuntimeException('T13 webhook_events table exists outside migration history: ' . $table);
        }

        $pdo->exec(
            'CREATE TABLE ' . $this->quote($table) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NOT NULL,'
            . '`supplier_code` VARCHAR(50) NOT NULL,'
            . '`event_id` VARCHAR(191) NOT NULL,'
            . '`event_type` VARCHAR(50) DEFAULT NULL,'
            . '`external_order_no` VARCHAR(100) DEFAULT NULL,'
            . '`status` VARCHAR(30) NOT NULL DEFAULT \'received\','
            . '`supplier_raw_status` VARCHAR(50) DEFAULT NULL,'
            . '`raw_payload_json` JSON NOT NULL,'
            . '`processing_result` VARCHAR(30) DEFAULT NULL,'
            . '`processing_error` VARCHAR(500) DEFAULT NULL,'
            . '`duplicate_of_event_id` VARCHAR(191) DEFAULT NULL,'
            . '`received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '`processed_at` DATETIME NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uq_supplier_event` (`supplier_code`,`event_id`),'
            . 'KEY `idx_company_received` (`company_id`,`status`,`received_at`),'
            . 'KEY `idx_external_order` (`external_order_no`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'webhook_events'));
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
