<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $table = '`' . $prefix . 'geo_async_jobs`';
        $pdo->exec(
            'CREATE TABLE ' . $table . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NULL,'
            . '`queue` VARCHAR(64) NOT NULL,'
            . '`job_type` VARCHAR(191) NOT NULL,'
            . '`payload_json` JSON NOT NULL,'
            . '`status` VARCHAR(32) NOT NULL,'
            . '`attempts` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,'
            . '`idempotency_key` CHAR(64) NULL,'
            . '`available_at` DATETIME NOT NULL,'
            . '`reserved_at` DATETIME NULL,'
            . '`completed_at` DATETIME NULL,'
            . '`last_error_code` VARCHAR(64) NULL,'
            . '`created_at` DATETIME NOT NULL,'
            . '`updated_at` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uq_queue_idempotency` (`queue`, `idempotency_key`),'
            . 'KEY `idx_ready` (`queue`, `status`, `available_at`),'
            . 'KEY `idx_company_status` (`company_id`, `status`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `' . $prefix . 'geo_async_jobs`');
    }
};
