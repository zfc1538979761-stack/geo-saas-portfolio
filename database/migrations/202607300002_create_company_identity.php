<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $companies = $prefix . 'companies';
        $users = $prefix . 'user';
        $tokens = $prefix . 'user_token';
        $throttles = $prefix . 'geo_auth_throttles';

        if ($this->tableExists($pdo, $companies) || $this->tableExists($pdo, $throttles)) {
            throw new RuntimeException('T02 identity tables already exist outside migration history.');
        }

        $fastAdminUserTable = $this->tableExists($pdo, $users);
        $tokenTableExists = $this->tableExists($pdo, $tokens);
        if ($fastAdminUserTable) {
            if (!$this->columnExists($pdo, $users, 'group_id') || !$tokenTableExists) {
                throw new RuntimeException('Existing user identity tables are not the supported FastAdmin schema.');
            }
            foreach (['company_id', 'password_hash', 'role'] as $column) {
                if ($this->columnExists($pdo, $users, $column)) {
                    throw new RuntimeException('Existing user identity columns require an explicit migration mapping.');
                }
            }
            if ($this->indexExists($pdo, $users, 'idx_company_status')) {
                throw new RuntimeException('Existing user identity index requires an explicit migration mapping.');
            }
            if ($this->columnExists($pdo, $tokens, 'purpose')) {
                throw new RuntimeException('Existing token purpose column requires an explicit migration mapping.');
            }
        } elseif ($tokenTableExists) {
            throw new RuntimeException('Existing token table without a supported user table requires manual review.');
        }

        $pdo->exec(
            'CREATE TABLE ' . $this->quote($companies) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`name` VARCHAR(191) NOT NULL,'
            . "`status` VARCHAR(30) NOT NULL DEFAULT 'normal',"
            . '`createtime` BIGINT UNSIGNED NOT NULL,'
            . '`updatetime` BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'KEY `idx_status_created` (`status`, `createtime`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!$fastAdminUserTable) {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($users) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NULL,'
                . "`username` VARCHAR(191) NOT NULL DEFAULT '',"
                . '`password_hash` VARCHAR(255) NULL,'
                . "`role` VARCHAR(32) NOT NULL DEFAULT 'company_owner',"
                . "`status` VARCHAR(30) NOT NULL DEFAULT 'normal',"
                . '`prevtime` BIGINT UNSIGNED NULL,'
                . '`logintime` BIGINT UNSIGNED NULL,'
                . "`loginip` VARCHAR(50) NOT NULL DEFAULT '',"
                . '`loginfailure` INT UNSIGNED NOT NULL DEFAULT 0,'
                . '`loginfailuretime` BIGINT UNSIGNED NULL,'
                . '`createtime` BIGINT UNSIGNED NOT NULL,'
                . '`updatetime` BIGINT UNSIGNED NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_username` (`username`),'
                . 'KEY `idx_company_status` (`company_id`, `status`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            $this->addColumnIfMissing(
                $pdo,
                $users,
                'company_id',
                '`company_id` BIGINT UNSIGNED NULL AFTER `id`'
            );
            $this->addColumnIfMissing(
                $pdo,
                $users,
                'password_hash',
                '`password_hash` VARCHAR(255) NULL AFTER `password`'
            );
            $this->addColumnIfMissing(
                $pdo,
                $users,
                'role',
                "`role` VARCHAR(32) NOT NULL DEFAULT 'company_owner' AFTER `password_hash`"
            );
            if (!$this->indexExists($pdo, $users, 'idx_company_status')) {
                $pdo->exec(
                    'ALTER TABLE ' . $this->quote($users)
                    . ' ADD KEY `idx_company_status` (`company_id`, `status`)'
                );
            }
        }

        if (!$this->tableExists($pdo, $tokens)) {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($tokens) . ' ('
                . '`token` VARCHAR(50) NOT NULL,'
                . '`user_id` BIGINT UNSIGNED NOT NULL,'
                . "`purpose` VARCHAR(32) NOT NULL DEFAULT 'legacy',"
                . '`createtime` BIGINT UNSIGNED NOT NULL,'
                . '`expiretime` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (`token`),'
                . 'KEY `idx_user_expire` (`user_id`, `expiretime`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($tokens)
                . " ADD COLUMN `purpose` VARCHAR(32) NOT NULL DEFAULT 'legacy' AFTER `user_id`"
            );
        }

        $pdo->exec(
            'CREATE TABLE ' . $this->quote($throttles) . ' ('
            . '`throttle_key` CHAR(64) NOT NULL,'
            . '`failures` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`window_started_at` BIGINT UNSIGNED NOT NULL,'
            . '`blocked_until` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . '`expires_at` BIGINT UNSIGNED NOT NULL,'
            . '`updated_at` BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`throttle_key`),'
            . 'KEY `idx_expires_at` (`expires_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $companies = $prefix . 'companies';
        $users = $prefix . 'user';
        $tokens = $prefix . 'user_token';
        $throttles = $prefix . 'geo_auth_throttles';

        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($throttles));

        if ($this->tableExists($pdo, $users)) {
            $fastAdminUserTable = $this->columnExists($pdo, $users, 'group_id');
            if ($fastAdminUserTable) {
                if ($this->indexExists($pdo, $users, 'idx_company_status')) {
                    $pdo->exec('ALTER TABLE ' . $this->quote($users) . ' DROP INDEX `idx_company_status`');
                }
                foreach (['role', 'password_hash', 'company_id'] as $column) {
                    if ($this->columnExists($pdo, $users, $column)) {
                        $pdo->exec(
                            'ALTER TABLE ' . $this->quote($users)
                            . ' DROP COLUMN ' . $this->quote($column)
                        );
                    }
                }
                if (
                    $this->tableExists($pdo, $tokens)
                    && $this->columnExists($pdo, $tokens, 'purpose')
                ) {
                    $pdo->exec(
                        'ALTER TABLE ' . $this->quote($tokens)
                        . ' DROP COLUMN `purpose`'
                    );
                }
            } else {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($tokens));
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($users));
            }
        }

        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($companies));
    }

    private function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!$this->columnExists($pdo, $table, $column)) {
            $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $definition);
        }
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

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index'
        );
        $statement->execute(['table' => $table, 'index' => $index]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }
};
