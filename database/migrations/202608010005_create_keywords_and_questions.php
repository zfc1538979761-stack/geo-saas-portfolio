<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @tenant-resource keywords
// @tenant-resource questions
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $companies = $prefix . 'companies';
        $keywords = $prefix . 'keywords';
        $questions = $prefix . 'questions';
        if (!$this->tableExists($pdo, $companies)) {
            throw new RuntimeException('T05 requires the T02 companies table.');
        }
        if ($this->tableExists($pdo, $keywords) || $this->tableExists($pdo, $questions)) {
            throw new RuntimeException('T05 keyword or question table exists outside migration history.');
        }

        $keywordCreated = false;
        $questionCreated = false;
        try {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($keywords) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NOT NULL,'
            . '`keyword` VARCHAR(191) NOT NULL,'
            . "`status` VARCHAR(20) NOT NULL DEFAULT 'normal',"
            . '`created_by` BIGINT UNSIGNED NOT NULL,'
            . '`updated_by` BIGINT UNSIGNED NOT NULL,'
            . '`createtime` BIGINT UNSIGNED NOT NULL,'
            . '`updatetime` BIGINT UNSIGNED NOT NULL,'
            . '`deletetime` BIGINT UNSIGNED NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uq_keywords_company_keyword` (`company_id`,`keyword`),'
            . 'UNIQUE KEY `uq_keywords_company_id` (`company_id`,`id`),'
            . 'KEY `idx_keywords_company_status_created` (`company_id`,`status`,`createtime`,`id`),'
            . 'KEY `idx_keywords_company_deleted_created` (`company_id`,`deletetime`,`createtime`,`id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('keyword_company', $prefix))
            . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
            . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $keywordCreated = true;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($questions) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NOT NULL,'
            . '`keyword_id` BIGINT UNSIGNED NOT NULL,'
            . '`text` TEXT NOT NULL,'
            . '`text_hash` BINARY(32) NOT NULL,'
            . "`source` VARCHAR(20) NOT NULL DEFAULT 'manual',"
            . "`status` VARCHAR(20) NOT NULL DEFAULT 'normal',"
            . '`created_by` BIGINT UNSIGNED NOT NULL,'
            . '`updated_by` BIGINT UNSIGNED NOT NULL,'
            . '`createtime` BIGINT UNSIGNED NOT NULL,'
            . '`updatetime` BIGINT UNSIGNED NOT NULL,'
            . '`deletetime` BIGINT UNSIGNED NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uq_questions_keyword_hash` (`company_id`,`keyword_id`,`text_hash`),'
            . 'KEY `idx_questions_company_keyword_status` (`company_id`,`keyword_id`,`status`,`createtime`,`id`),'
            . 'KEY `idx_questions_company_status_created` (`company_id`,`status`,`createtime`,`id`),'
            . 'KEY `idx_questions_company_deleted_created` (`company_id`,`deletetime`,`createtime`,`id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('question_company', $prefix))
            . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
            . ' ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('question_keyword', $prefix))
            . ' FOREIGN KEY (`company_id`,`keyword_id`) REFERENCES ' . $this->quote($keywords)
            . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $questionCreated = true;
        } catch (Throwable $exception) {
            if ($questionCreated) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($questions));
            }
            if ($keywordCreated) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($keywords));
            }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $keywords = $prefix . 'keywords';
        $questions = $prefix . 'questions';
        if ($this->hasExternalReference($pdo, $questions, [])) {
            throw new RuntimeException('T05 rollback refuses to drop referenced questions.');
        }
        if ($this->hasExternalReference($pdo, $keywords, [$questions])) {
            throw new RuntimeException('T05 rollback refuses to drop referenced keywords.');
        }
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($questions));
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($keywords));
    }

    private function hasExternalReference(PDO $pdo, string $referenced, array $allowedTables): bool
    {
        $statement = $pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=:table'
        );
        $statement->execute(['table' => $referenced]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!in_array((string) $table, $allowedTables, true)) {
                return true;
            }
        }
        return false;
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

    private function constraint(string $name, string $prefix): string
    {
        return 'fk_geo_' . $name . '_' . substr(sha1($prefix), 0, 10);
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
