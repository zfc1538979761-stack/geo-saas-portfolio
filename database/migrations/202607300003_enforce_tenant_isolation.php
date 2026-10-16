<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $companies = $prefix . 'companies';
        $users = $prefix . 'user';
        $auditLogs = $prefix . 'audit_logs';
        $userForeignKey = $this->constraintName('user_company', $prefix);
        $auditForeignKey = $this->constraintName('audit_company', $prefix);

        if (!$this->tableExists($pdo, $companies) || !$this->tableExists($pdo, $users)) {
            throw new RuntimeException('T03 requires the T02 company identity migration.');
        }
        if ($this->tableExists($pdo, $auditLogs)) {
            throw new RuntimeException('T03 audit table already exists outside migration history.');
        }
        if (
            !$this->columnExists($pdo, $users, 'company_id')
            || !$this->columnExists($pdo, $companies, 'id')
        ) {
            throw new RuntimeException('T03 tenant identity columns are missing.');
        }
        if (!$this->hasCompanyLeadingIndex($pdo, $users)) {
            throw new RuntimeException('T03 requires a user index beginning with company_id.');
        }
        if ($this->foreignKeysForColumn($pdo, $users, 'company_id') !== []) {
            throw new RuntimeException('Existing user.company_id foreign key requires explicit migration mapping.');
        }

        $invalidReferences = (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $this->quote($users) . ' u'
            . ' LEFT JOIN ' . $this->quote($companies) . ' c ON c.`id`=u.`company_id`'
            . ' WHERE u.`company_id` IS NOT NULL AND (u.`company_id`<1 OR c.`id` IS NULL)'
        )->fetchColumn();
        if ($invalidReferences > 0) {
            throw new RuntimeException('Invalid user.company_id values must be resolved before T03 migration.');
        }

        $auditCreated = false;
        $userForeignKeyAdded = false;
        try {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($auditLogs) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`actor_id` BIGINT UNSIGNED NOT NULL,'
                . "`actor_type` VARCHAR(32) NOT NULL DEFAULT 'enterprise_user',"
                . '`company_id` BIGINT UNSIGNED NULL,'
                . '`action` VARCHAR(100) NOT NULL,'
                . '`target_type` VARCHAR(100) NOT NULL,'
                . '`target_id` VARCHAR(191) NULL,'
                . "`ip_address` VARCHAR(45) NOT NULL DEFAULT '',"
                . '`detail_json` JSON NOT NULL,'
                . '`created_at` DATETIME(6) NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_company_created` (`company_id`,`created_at`),'
                . 'KEY `idx_company_action_created` (`company_id`,`action`,`created_at`),'
                . 'KEY `idx_actor_created` (`actor_id`,`created_at`),'
                . 'CONSTRAINT ' . $this->quote($auditForeignKey)
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $auditCreated = true;
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($users)
                . ' ADD CONSTRAINT ' . $this->quote($userForeignKey)
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
            );
            $userForeignKeyAdded = true;
        } catch (Throwable $exception) {
            if ($userForeignKeyAdded && $this->constraintExists($pdo, $users, $userForeignKey)) {
                $pdo->exec(
                    'ALTER TABLE ' . $this->quote($users)
                    . ' DROP FOREIGN KEY ' . $this->quote($userForeignKey)
                );
            }
            if ($auditCreated) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($auditLogs));
            }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $users = $prefix . 'user';
        $auditLogs = $prefix . 'audit_logs';
        $userForeignKey = $this->constraintName('user_company', $prefix);

        if (
            $this->tableExists($pdo, $auditLogs)
            && (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $this->quote($auditLogs)
            )->fetchColumn() > 0
        ) {
            throw new RuntimeException('T03 rollback refused because audit logs are not empty.');
        }
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($auditLogs));
        if ($this->tableExists($pdo, $users) && $this->constraintExists($pdo, $users, $userForeignKey)) {
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($users)
                . ' DROP FOREIGN KEY ' . $this->quote($userForeignKey)
            );
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

    private function hasCompanyLeadingIndex(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
            . " AND COLUMN_NAME='company_id' AND SEQ_IN_INDEX=1"
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function foreignKeysForColumn(PDO $pdo, string $table, string $column): array
    {
        $statement = $pdo->prepare(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
            . ' AND COLUMN_NAME=:column AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function constraintExists(PDO $pdo, string $table, string $constraint): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
            . ' AND CONSTRAINT_NAME=:constraint'
        );
        $statement->execute(['table' => $table, 'constraint' => $constraint]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function constraintName(string $purpose, string $prefix): string
    {
        return 'fk_geo_' . $purpose . '_' . substr(sha1($prefix), 0, 12);
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
