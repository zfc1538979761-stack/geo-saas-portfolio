<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $refunds = $prefix . 'publish_refund_requests';

        if ($this->tableExists($pdo, $refunds)) {
            throw new RuntimeException('T15 publish_refund_requests table exists outside migration history: ' . $refunds);
        }

        $pdo->exec(
            'CREATE TABLE ' . $this->quote($refunds) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NOT NULL,'
            . '`order_id` BIGINT UNSIGNED NOT NULL,'
            . '`request_no` VARCHAR(50) NOT NULL,'
            . '`status` VARCHAR(30) NOT NULL DEFAULT \'pending_review\','
            . '`total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
            . '`reason` VARCHAR(500) DEFAULT NULL,'
            . '`review_note` VARCHAR(500) DEFAULT NULL,'
            . '`requested_by` BIGINT UNSIGNED NOT NULL,'
            . '`reviewed_at` DATETIME NULL,'
            . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uq_request_no` (`request_no`),'
            . 'UNIQUE KEY `uq_company_order_active` (`company_id`,`order_id`,`status`),'
            . 'KEY `idx_company_status` (`company_id`,`status`,`id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'publish_refund_requests'));
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
