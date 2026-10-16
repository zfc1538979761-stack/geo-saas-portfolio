<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $notifications = $prefix . 'publish_notifications';

        if ($this->tableExists($pdo, $notifications)) {
            throw new RuntimeException('T14 publish_notifications table exists outside migration history: ' . $notifications);
        }

        $pdo->exec(
            'CREATE TABLE ' . $this->quote($notifications) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NOT NULL,'
            . '`order_id` BIGINT UNSIGNED NOT NULL,'
            . '`order_item_id` BIGINT UNSIGNED DEFAULT NULL,'
            . '`type` VARCHAR(30) NOT NULL COMMENT \'published/rejected/order_status\','
            . '`title` VARCHAR(200) NOT NULL,'
            . '`content` JSON DEFAULT NULL,'
            . '`is_read` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '`read_at` DATETIME NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'KEY `idx_company_read` (`company_id`,`is_read`,`id`),'
            . 'KEY `idx_order` (`company_id`,`order_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'publish_notifications'));
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
