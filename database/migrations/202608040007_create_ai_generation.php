<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @tenant-resource ai_calls
// @tenant-resource geo_async_jobs
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $companies = $prefix . 'companies';
        $jobs = $prefix . 'geo_async_jobs';
        $articles = $prefix . 'articles';
        $versions = $prefix . 'article_versions';
        $providers = $prefix . 'ai_providers';
        $calls = $prefix . 'ai_calls';
        foreach ([$companies, $jobs, $articles, $versions] as $required) {
            if (!$this->tableExists($pdo, $required)) {
                throw new RuntimeException('T07 requires the T01, T02, and T06 tables.');
            }
        }
        foreach ([$providers, $calls] as $table) {
            if ($this->tableExists($pdo, $table)) {
                throw new RuntimeException('T07 table exists outside migration history.');
            }
        }
        foreach (['actor_id', 'provider_code', 'input_snapshot_json', 'result_article_id', 'result_version_id'] as $column) {
            if ($this->columnExists($pdo, $jobs, $column)) {
                throw new RuntimeException('T07 jobs column exists outside migration history.');
            }
        }
        if ($this->columnExists($pdo, $versions, 'ai_call_id')) {
            throw new RuntimeException('T07 article provenance column exists outside migration history.');
        }

        $providersCreated = false;
        $jobsExtended = false;
        $callsCreated = false;
        $versionsExtended = false;
        $jobResultConstraints = false;
        try {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($providers) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`code` VARCHAR(64) NOT NULL,'
                . '`display_name` VARCHAR(191) NOT NULL,'
                . "`status` VARCHAR(20) NOT NULL DEFAULT 'normal',"
                . '`created_at` DATETIME NOT NULL,'
                . '`updated_at` DATETIME NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_ai_providers_code` (`code`),'
                . 'KEY `idx_ai_providers_status` (`status`,`id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $providersCreated = true;
            $pdo->exec(
                'INSERT INTO ' . $this->quote($providers)
                . ' (`code`,`display_name`,`status`,`created_at`,`updated_at`) VALUES '
                . "('mock','Deterministic Mock','normal',NOW(),NOW()),"
                . "('doubao_ark','Doubao / Volcengine Ark','normal',NOW(),NOW())"
            );

            $pdo->exec(
                'ALTER TABLE ' . $this->quote($jobs)
                . ' ADD COLUMN `actor_id` BIGINT UNSIGNED NULL AFTER `company_id`,'
                . ' ADD COLUMN `provider_code` VARCHAR(64) NULL AFTER `job_type`,'
                . ' ADD COLUMN `subject_type` VARCHAR(32) NULL AFTER `provider_code`,'
                . ' ADD COLUMN `subject_id` BIGINT UNSIGNED NULL AFTER `subject_type`,'
                . ' ADD COLUMN `input_snapshot_json` JSON NULL AFTER `payload_json`,'
                . ' ADD COLUMN `started_at` DATETIME NULL AFTER `reserved_at`,'
                . ' ADD COLUMN `lease_expires_at` DATETIME NULL AFTER `started_at`,'
                . ' ADD COLUMN `failed_at` DATETIME NULL AFTER `completed_at`,'
                . ' ADD COLUMN `cancelled_at` DATETIME NULL AFTER `failed_at`,'
                . ' ADD COLUMN `result_article_id` BIGINT UNSIGNED NULL AFTER `cancelled_at`,'
                . ' ADD COLUMN `result_version_id` BIGINT UNSIGNED NULL AFTER `result_article_id`,'
                . ' ADD COLUMN `last_error_message` VARCHAR(255) NULL AFTER `last_error_code`,'
                . ' ADD UNIQUE KEY `uq_jobs_company_id` (`company_id`,`id`),'
                . ' ADD KEY `idx_jobs_company_queue_status` (`company_id`,`queue`,`status`,`available_at`,`id`),'
                . ' ADD KEY `idx_jobs_company_result_article` (`company_id`,`result_article_id`),'
                . ' ADD KEY `idx_jobs_company_result_version` (`company_id`,`result_version_id`),'
                . ' ADD CONSTRAINT ' . $this->quote($this->constraint('job_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
            );
            $jobsExtended = true;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($calls) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`job_id` BIGINT UNSIGNED NOT NULL,'
                . '`provider_id` BIGINT UNSIGNED NOT NULL,'
                . '`provider_code` VARCHAR(64) NOT NULL,'
                . '`model_name` VARCHAR(191) NOT NULL,'
                . "`purpose` VARCHAR(64) NOT NULL DEFAULT 'article_generation',"
                . '`attempt_no` INT UNSIGNED NOT NULL,'
                . '`input_tokens` INT UNSIGNED NULL,'
                . '`output_tokens` INT UNSIGNED NULL,'
                . '`cost_amount` DECIMAL(20,6) NULL,'
                . '`currency` CHAR(3) NULL,'
                . '`pricing_version` VARCHAR(64) NULL,'
                . '`latency_ms` INT UNSIGNED NULL,'
                . '`provider_request_id` VARCHAR(191) NULL,'
                . '`status` VARCHAR(32) NOT NULL,'
                . '`error_category` VARCHAR(64) NULL,'
                . '`started_at` DATETIME NOT NULL,'
                . '`completed_at` DATETIME NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_ai_calls_company_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_ai_calls_job_attempt` (`company_id`,`job_id`,`attempt_no`),'
                . 'KEY `idx_ai_calls_company_status_started` (`company_id`,`status`,`started_at`,`id`),'
                . 'KEY `idx_ai_calls_provider_started` (`provider_id`,`started_at`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('call_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('call_job', $prefix))
                . ' FOREIGN KEY (`company_id`,`job_id`) REFERENCES ' . $this->quote($jobs)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('call_provider', $prefix))
                . ' FOREIGN KEY (`provider_id`) REFERENCES ' . $this->quote($providers) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $callsCreated = true;

            $pdo->exec(
                'ALTER TABLE ' . $this->quote($versions)
                . ' ADD COLUMN `ai_call_id` BIGINT UNSIGNED NULL AFTER `prompt_template_id`,'
                . ' ADD KEY `idx_versions_company_ai_call` (`company_id`,`ai_call_id`),'
                . ' ADD CONSTRAINT ' . $this->quote($this->constraint('version_ai_call', $prefix))
                . ' FOREIGN KEY (`company_id`,`ai_call_id`) REFERENCES ' . $this->quote($calls)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            );
            $versionsExtended = true;

            $pdo->exec(
                'ALTER TABLE ' . $this->quote($jobs)
                . ' ADD CONSTRAINT ' . $this->quote($this->constraint('job_result_article', $prefix))
                . ' FOREIGN KEY (`company_id`,`result_article_id`) REFERENCES ' . $this->quote($articles)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . ' ADD CONSTRAINT ' . $this->quote($this->constraint('job_result_version', $prefix))
                . ' FOREIGN KEY (`company_id`,`result_version_id`) REFERENCES ' . $this->quote($versions)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            );
            $jobResultConstraints = true;
        } catch (Throwable $exception) {
            if ($jobResultConstraints) { $this->dropJobResultConstraints($pdo, $jobs, $prefix); }
            if ($versionsExtended) { $this->dropVersionExtension($pdo, $versions, $prefix); }
            if ($callsCreated) { $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($calls)); }
            if ($jobsExtended) { $this->dropJobExtension($pdo, $jobs, $prefix); }
            if ($providersCreated) { $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($providers)); }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $jobs = $prefix . 'geo_async_jobs';
        $versions = $prefix . 'article_versions';
        $calls = $prefix . 'ai_calls';
        $providers = $prefix . 'ai_providers';
        if ($this->tableExists($pdo, $jobs)) { $this->dropJobResultConstraints($pdo, $jobs, $prefix); }
        if ($this->tableExists($pdo, $versions) && $this->columnExists($pdo, $versions, 'ai_call_id')) {
            $this->dropVersionExtension($pdo, $versions, $prefix);
        }
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($calls));
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($providers));
        if ($this->tableExists($pdo, $jobs) && $this->columnExists($pdo, $jobs, 'actor_id')) {
            $this->dropJobExtension($pdo, $jobs, $prefix);
        }
    }

    private function dropJobResultConstraints(PDO $pdo, string $jobs, string $prefix): void
    {
        foreach (['job_result_article', 'job_result_version'] as $name) {
            $constraint = $this->constraint($name, $prefix);
            if ($this->constraintExists($pdo, $jobs, $constraint)) {
                $pdo->exec('ALTER TABLE ' . $this->quote($jobs) . ' DROP FOREIGN KEY ' . $this->quote($constraint));
            }
        }
    }

    private function dropVersionExtension(PDO $pdo, string $versions, string $prefix): void
    {
        $constraint = $this->constraint('version_ai_call', $prefix);
        if ($this->constraintExists($pdo, $versions, $constraint)) {
            $pdo->exec('ALTER TABLE ' . $this->quote($versions) . ' DROP FOREIGN KEY ' . $this->quote($constraint));
        }
        $pdo->exec('ALTER TABLE ' . $this->quote($versions) . ' DROP INDEX `idx_versions_company_ai_call`, DROP COLUMN `ai_call_id`');
    }

    private function dropJobExtension(PDO $pdo, string $jobs, string $prefix): void
    {
        $constraint = $this->constraint('job_company', $prefix);
        if ($this->constraintExists($pdo, $jobs, $constraint)) {
            $pdo->exec('ALTER TABLE ' . $this->quote($jobs) . ' DROP FOREIGN KEY ' . $this->quote($constraint));
        }
        $pdo->exec(
            'ALTER TABLE ' . $this->quote($jobs)
            . ' DROP INDEX `idx_jobs_company_result_version`,'
            . ' DROP INDEX `idx_jobs_company_result_article`,'
            . ' DROP INDEX `idx_jobs_company_queue_status`,'
            . ' DROP INDEX `uq_jobs_company_id`,'
            . ' DROP COLUMN `last_error_message`,'
            . ' DROP COLUMN `result_version_id`,'
            . ' DROP COLUMN `result_article_id`,'
            . ' DROP COLUMN `cancelled_at`,'
            . ' DROP COLUMN `failed_at`,'
            . ' DROP COLUMN `lease_expires_at`,'
            . ' DROP COLUMN `started_at`,'
            . ' DROP COLUMN `input_snapshot_json`,'
            . ' DROP COLUMN `subject_id`,'
            . ' DROP COLUMN `subject_type`,'
            . ' DROP COLUMN `provider_code`,'
            . ' DROP COLUMN `actor_id`'
        );
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()'
            . ' AND TABLE_NAME=:table AND COLUMN_NAME=:column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function constraintExists(PDO $pdo, string $table, string $constraint): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE()'
            . ' AND TABLE_NAME=:table AND CONSTRAINT_NAME=:constraint'
        );
        $statement->execute(['table' => $table, 'constraint' => $constraint]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function constraint(string $name, string $prefix): string
    {
        return 'fk_geo_t07_' . $name . '_' . substr(sha1($prefix), 0, 8);
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
