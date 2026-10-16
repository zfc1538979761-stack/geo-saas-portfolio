<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @tenant-resource media_favorites
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'media_favorites';
        if ($this->tableExists($pdo, $table)) {
            throw new RuntimeException('media_favorites table exists outside migration history.');
        }
        $pdo->exec('CREATE TABLE ' . $this->quote($table) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NOT NULL,'
            . '`media_resource_id` BIGINT UNSIGNED NOT NULL,'
            . "`source_catalog` VARCHAR(63) NOT NULL DEFAULT '',"
            . '`created_at` DATETIME NOT NULL,'
            . '`updated_at` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uq_media_favorites_company_media` (`company_id`,`media_resource_id`),'
            . 'KEY `idx_media_favorites_company_created` (`company_id`,`created_at`,`id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('media_favorite_company', $prefix))
            . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($prefix . 'companies') . ' (`id`)'
            . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'media_favorites'));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function constraint(string $name, string $prefix): string
    {
        return 'fk_geo_' . $name . '_' . substr(sha1($prefix), 0, 8);
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
