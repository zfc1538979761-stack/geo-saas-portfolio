<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @global-resource reason=T08 supplier and channel catalogs are global configuration tables without company_id.
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $suppliers = $prefix . 'suppliers';
        $channels = $prefix . 'channels';
        foreach ([$suppliers, $channels] as $table) {
            if ($this->tableExists($pdo, $table)) {
                throw new RuntimeException('T08 table exists outside migration history.');
            }
        }

        $created = [];
        try {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($suppliers) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`code` VARCHAR(64) NOT NULL,'
                . '`display_name` VARCHAR(191) NOT NULL,'
                . "`status` VARCHAR(20) NOT NULL DEFAULT 'normal',"
                . '`created_at` DATETIME NOT NULL,'
                . '`updated_at` DATETIME NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_suppliers_code` (`code`),'
                . 'KEY `idx_suppliers_status` (`status`,`id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $suppliers;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($channels) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`code` VARCHAR(64) NOT NULL,'
                . '`name` VARCHAR(191) NOT NULL,'
                . '`category` VARCHAR(32) NOT NULL,'
                . "`status` VARCHAR(20) NOT NULL DEFAULT 'normal',"
                . '`supplier_confirmed` TINYINT(1) NOT NULL DEFAULT 0,'
                . '`created_at` DATETIME NOT NULL,'
                . '`updated_at` DATETIME NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_channels_code` (`code`),'
                . 'KEY `idx_channels_status` (`status`,`id`),'
                . 'KEY `idx_channels_category_status` (`category`,`status`,`id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $channels;

            $pdo->exec(
                'INSERT INTO ' . $this->quote($suppliers)
                . ' (`code`,`display_name`,`status`,`created_at`,`updated_at`) VALUES '
                . "('mock','Deterministic Mock','normal',NOW(),NOW())"
                . ' ON DUPLICATE KEY UPDATE `updated_at`=NOW()'
            );
            $pdo->exec(
                'INSERT INTO ' . $this->quote($channels)
                . ' (`code`,`name`,`category`,`status`,`supplier_confirmed`,`created_at`,`updated_at`) VALUES '
                . "('official_media','Official Media','official_media','normal',0,NOW(),NOW()),"
                . "('influencer','Self-Media KOL','influencer','normal',0,NOW(),NOW()),"
                . "('b2b','B2B','b2b','normal',0,NOW(),NOW())"
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
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'channels'));
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'suppliers'));
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
