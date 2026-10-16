<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @tenant-resource company_profiles
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $companies = $prefix . 'companies';
        $profiles = $prefix . 'company_profiles';
        $foreignKey = $this->constraintName($prefix);

        if (!$this->tableExists($pdo, $companies)) {
            throw new RuntimeException('T04 requires the T02 companies table.');
        }
        if ($this->tableExists($pdo, $profiles)) {
            throw new RuntimeException('T04 company_profiles already exists outside migration history.');
        }

        $pdo->exec(
            'CREATE TABLE ' . $this->quote($profiles) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NOT NULL,'
            . '`brand` VARCHAR(100) NULL,'
            . '`industry` VARCHAR(100) NULL,'
            . '`products_json` JSON NOT NULL,'
            . '`website` VARCHAR(2048) NULL,'
            . '`introduction` TEXT NULL,'
            . '`contacts_ciphertext` TEXT NULL,'
            . '`createtime` BIGINT UNSIGNED NOT NULL,'
            . '`updatetime` BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uq_company_profile_company` (`company_id`),'
            . 'CONSTRAINT ' . $this->quote($foreignKey)
            . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
            . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'company_profiles'));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function constraintName(string $prefix): string
    {
        return 'fk_geo_profile_company_' . substr(sha1($prefix), 0, 12);
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
