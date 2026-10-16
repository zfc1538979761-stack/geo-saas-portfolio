<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @tenant-resource geo_question_generation_batches
// @tenant-resource geo_answer_versions
// @tenant-resource geo_visibility_plans
// @tenant-resource geo_visibility_checks
// @tenant-resource geo_visibility_results
// @tenant-resource geo_visibility_comparisons
// @tenant-resource geo_optimization_suggestions
// @tenant-resource questions
// @tenant-resource article_versions
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $required = ['companies', 'questions', 'article_versions', 'geo_async_jobs', 'ai_calls'];
        foreach ($required as $table) {
            if (!$this->tableExists($pdo, $prefix . $table)) {
                throw new RuntimeException('T19 phase 1 requires the T02, T05, T06, and T07 tables.');
            }
        }
        $newTables = [
            'geo_question_generation_batches', 'geo_answer_versions', 'geo_visibility_plans',
            'geo_visibility_checks', 'geo_visibility_results', 'geo_visibility_comparisons',
            'geo_optimization_suggestions',
        ];
        foreach ($newTables as $table) {
            if ($this->tableExists($pdo, $prefix . $table)) {
                throw new RuntimeException('T19 phase-1 table exists outside migration history.');
            }
        }
        if ($this->columnExists($pdo, $prefix . 'questions', 'generation_batch_id')
            || $this->columnExists($pdo, $prefix . 'article_versions', 'geo_answer_version_id')) {
            throw new RuntimeException('T19 phase-1 extension exists outside migration history.');
        }

        try {
            $this->createQuestionBatches($pdo, $prefix);
            $this->extendQuestions($pdo, $prefix);
            $this->createAnswerVersions($pdo, $prefix);
            $this->extendArticleVersions($pdo, $prefix);
            $this->createVisibilityPlans($pdo, $prefix);
            $this->createVisibilityChecks($pdo, $prefix);
            $this->createVisibilityResults($pdo, $prefix);
            $this->createVisibilityComparisons($pdo, $prefix);
            $this->createOptimizationSuggestions($pdo, $prefix);
        } catch (Throwable $exception) {
            // MySQL DDL auto-commits. Remove only objects whose absence was
            // verified above so an interrupted migration can be retried.
            $this->down($pdo, $prefix);
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        foreach (['geo_optimization_suggestions', 'geo_visibility_comparisons', 'geo_visibility_results', 'geo_visibility_checks', 'geo_visibility_plans'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . $table));
        }
        $versions = $prefix . 'article_versions';
        if ($this->columnExists($pdo, $versions, 'geo_answer_version_id')) {
            $this->dropConstraint($pdo, $versions, $this->constraint('article_answer', $prefix));
            $pdo->exec('ALTER TABLE ' . $this->quote($versions)
                . ' DROP INDEX `idx_article_versions_company_geo_answer`, DROP COLUMN `geo_answer_version_id`');
        }
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'geo_answer_versions'));
        $questions = $prefix . 'questions';
        if ($this->columnExists($pdo, $questions, 'generation_batch_id')) {
            $this->dropConstraint($pdo, $questions, $this->constraint('question_batch', $prefix));
            $pdo->exec('ALTER TABLE ' . $this->quote($questions)
                . ' DROP INDEX `idx_questions_company_generation_batch`,'
                . ' DROP INDEX `uq_t19_questions_company_id`,'
                . ' DROP COLUMN `reviewed_at`, DROP COLUMN `reviewed_by`, DROP COLUMN `generation_batch_id`');
        }
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'geo_question_generation_batches'));
    }

    private function createQuestionBatches(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'geo_question_generation_batches';
        $pdo->exec('CREATE TABLE ' . $this->quote($table) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`company_id` BIGINT UNSIGNED NOT NULL,`job_id` BIGINT UNSIGNED NULL,`ai_call_id` BIGINT UNSIGNED NULL,'
            . '`provider_code` VARCHAR(64) NOT NULL,`model_name` VARCHAR(191) NOT NULL,`provider_request_id` VARCHAR(191) NULL,'
            . '`input_snapshot_json` JSON NOT NULL,`generated_count` INT UNSIGNED NOT NULL DEFAULT 0,`duplicate_count` INT UNSIGNED NOT NULL DEFAULT 0,'
            . "`status` VARCHAR(32) NOT NULL DEFAULT 'running',`error_category` VARCHAR(64) NULL,"
            . '`created_by` BIGINT UNSIGNED NOT NULL,`created_at` DATETIME NOT NULL,`completed_at` DATETIME NULL,'
            . 'PRIMARY KEY (`id`),UNIQUE KEY `uq_geo_question_batches_company_id` (`company_id`,`id`),'
            . 'KEY `idx_geo_question_batches_company_status` (`company_id`,`status`,`created_at`,`id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('question_batch_company', $prefix))
            . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($prefix . 'companies') . ' (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('question_batch_job', $prefix))
            . ' FOREIGN KEY (`company_id`,`job_id`) REFERENCES ' . $this->quote($prefix . 'geo_async_jobs') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('question_batch_call', $prefix))
            . ' FOREIGN KEY (`company_id`,`ai_call_id`) REFERENCES ' . $this->quote($prefix . 'ai_calls') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function extendQuestions(PDO $pdo, string $prefix): void
    {
        $pdo->exec('ALTER TABLE ' . $this->quote($prefix . 'questions')
            . ' ADD COLUMN `generation_batch_id` BIGINT UNSIGNED NULL AFTER `source`,'
            . ' ADD COLUMN `reviewed_by` BIGINT UNSIGNED NULL AFTER `status`,'
            . ' ADD COLUMN `reviewed_at` DATETIME NULL AFTER `reviewed_by`,'
            . ' ADD UNIQUE KEY `uq_t19_questions_company_id` (`company_id`,`id`),'
            . ' ADD KEY `idx_questions_company_generation_batch` (`company_id`,`generation_batch_id`),'
            . ' ADD CONSTRAINT ' . $this->quote($this->constraint('question_batch', $prefix))
            . ' FOREIGN KEY (`company_id`,`generation_batch_id`) REFERENCES ' . $this->quote($prefix . 'geo_question_generation_batches')
            . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    private function createAnswerVersions(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'geo_answer_versions';
        $pdo->exec('CREATE TABLE ' . $this->quote($table) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`company_id` BIGINT UNSIGNED NOT NULL,'
            . '`question_id` BIGINT UNSIGNED NOT NULL,`keyword_id` BIGINT UNSIGNED NOT NULL,`version_no` INT UNSIGNED NOT NULL,'
            . '`answer_text` LONGTEXT NOT NULL,`recommendation_reason` TEXT NULL,`facts_json` JSON NOT NULL,`evidence_json` JSON NOT NULL,'
            . '`profile_snapshot_json` JSON NOT NULL,`knowledge_snapshot_json` JSON NOT NULL,'
            . '`job_id` BIGINT UNSIGNED NULL,`ai_call_id` BIGINT UNSIGNED NULL,`provider_code` VARCHAR(64) NULL,`model_name` VARCHAR(191) NULL,`provider_request_id` VARCHAR(191) NULL,'
            . "`source` VARCHAR(20) NOT NULL DEFAULT 'ai',`status` VARCHAR(32) NOT NULL DEFAULT 'pending_review',"
            . '`created_by` BIGINT UNSIGNED NOT NULL,`reviewed_by` BIGINT UNSIGNED NULL,`created_at` DATETIME NOT NULL,`reviewed_at` DATETIME NULL,'
            . 'PRIMARY KEY (`id`),UNIQUE KEY `uq_geo_answers_company_id` (`company_id`,`id`),'
            . 'UNIQUE KEY `uq_geo_answers_question_version` (`company_id`,`question_id`,`version_no`),'
            . 'KEY `idx_geo_answers_company_question_status` (`company_id`,`question_id`,`status`,`version_no`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('answer_company', $prefix)) . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($prefix . 'companies') . ' (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('answer_question', $prefix)) . ' FOREIGN KEY (`company_id`,`question_id`,`keyword_id`) REFERENCES ' . $this->quote($prefix . 'questions') . ' (`company_id`,`id`,`keyword_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('answer_job', $prefix)) . ' FOREIGN KEY (`company_id`,`job_id`) REFERENCES ' . $this->quote($prefix . 'geo_async_jobs') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('answer_call', $prefix)) . ' FOREIGN KEY (`company_id`,`ai_call_id`) REFERENCES ' . $this->quote($prefix . 'ai_calls') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function extendArticleVersions(PDO $pdo, string $prefix): void
    {
        $pdo->exec('ALTER TABLE ' . $this->quote($prefix . 'article_versions')
            . ' ADD COLUMN `geo_answer_version_id` BIGINT UNSIGNED NULL AFTER `ai_call_id`,'
            . ' ADD KEY `idx_article_versions_company_geo_answer` (`company_id`,`geo_answer_version_id`),'
            . ' ADD CONSTRAINT ' . $this->quote($this->constraint('article_answer', $prefix))
            . ' FOREIGN KEY (`company_id`,`geo_answer_version_id`) REFERENCES ' . $this->quote($prefix . 'geo_answer_versions')
            . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    private function createVisibilityPlans(PDO $pdo, string $prefix): void
    {
        $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'geo_visibility_plans') . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`company_id` BIGINT UNSIGNED NOT NULL,`question_id` BIGINT UNSIGNED NOT NULL,'
            . '`platform` VARCHAR(64) NOT NULL,`model_name` VARCHAR(191) NOT NULL,`provider_code` VARCHAR(64) NOT NULL,'
            . '`interval_days` TINYINT UNSIGNED NOT NULL DEFAULT 7,`timezone` VARCHAR(64) NOT NULL DEFAULT \'Asia/Shanghai\','
            . '`enabled` TINYINT(1) NOT NULL DEFAULT 1,`next_run_at` DATETIME NOT NULL,`last_run_at` DATETIME NULL,`config_version` INT UNSIGNED NOT NULL DEFAULT 1,'
            . '`created_by` BIGINT UNSIGNED NOT NULL,`updated_by` BIGINT UNSIGNED NOT NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id`),UNIQUE KEY `uq_visibility_plans_company_id` (`company_id`,`id`),'
            . 'UNIQUE KEY `uq_visibility_plan_dimension` (`company_id`,`question_id`,`platform`,`model_name`),'
            . 'KEY `idx_visibility_plans_due` (`enabled`,`next_run_at`,`id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_plan_company', $prefix)) . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($prefix . 'companies') . ' (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_plan_question', $prefix)) . ' FOREIGN KEY (`company_id`,`question_id`) REFERENCES ' . $this->quote($prefix . 'questions') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function createVisibilityChecks(PDO $pdo, string $prefix): void
    {
        $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'geo_visibility_checks') . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`company_id` BIGINT UNSIGNED NOT NULL,`plan_id` BIGINT UNSIGNED NULL,`question_id` BIGINT UNSIGNED NOT NULL,`job_id` BIGINT UNSIGNED NULL,'
            . '`question_snapshot` TEXT NOT NULL,`brand_snapshot` VARCHAR(191) NOT NULL,'
            . "`check_type` VARCHAR(20) NOT NULL DEFAULT 'initial',`provider_code` VARCHAR(64) NOT NULL,`platform` VARCHAR(64) NOT NULL,`model_name` VARCHAR(191) NOT NULL,"
            . '`scheduled_at` DATETIME NOT NULL,`executed_at` DATETIME NULL,`status` VARCHAR(32) NOT NULL,`idempotency_key` CHAR(64) NOT NULL,'
            . '`error_category` VARCHAR(64) NULL,`external_error_code` VARCHAR(64) NULL,`error_message` VARCHAR(255) NULL,`raw_response` MEDIUMTEXT NULL,`response_history_json` JSON NULL,`execution_evidence_json` JSON NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id`),UNIQUE KEY `uq_visibility_checks_company_id` (`company_id`,`id`),'
            . 'UNIQUE KEY `uq_visibility_checks_idempotency` (`company_id`,`idempotency_key`),'
            . 'KEY `idx_visibility_checks_history` (`company_id`,`question_id`,`platform`,`model_name`,`scheduled_at`,`id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_check_company', $prefix)) . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($prefix . 'companies') . ' (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_check_plan', $prefix)) . ' FOREIGN KEY (`company_id`,`plan_id`) REFERENCES ' . $this->quote($prefix . 'geo_visibility_plans') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_check_question', $prefix)) . ' FOREIGN KEY (`company_id`,`question_id`) REFERENCES ' . $this->quote($prefix . 'questions') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_check_job', $prefix)) . ' FOREIGN KEY (`company_id`,`job_id`) REFERENCES ' . $this->quote($prefix . 'geo_async_jobs') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function createVisibilityResults(PDO $pdo, string $prefix): void
    {
        $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'geo_visibility_results') . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`company_id` BIGINT UNSIGNED NOT NULL,`check_id` BIGINT UNSIGNED NOT NULL,`question_id` BIGINT UNSIGNED NOT NULL,'
            . '`question_snapshot` TEXT NOT NULL,`brand_snapshot` VARCHAR(191) NOT NULL,'
            . '`platform` VARCHAR(64) NOT NULL,`model_name` VARCHAR(191) NOT NULL,`detected_at` DATETIME NOT NULL,`raw_answer` LONGTEXT NOT NULL,'
            . '`visibility_status` VARCHAR(20) NOT NULL,`brand_mentioned` TINYINT(1) NOT NULL,`explicitly_recommended` TINYINT(1) NOT NULL,`recommendation_reason` TEXT NULL,'
            . '`citations_json` JSON NOT NULL,`evidence_json` JSON NOT NULL,`classifier_version` VARCHAR(64) NOT NULL,`created_at` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id`),UNIQUE KEY `uq_visibility_results_company_id` (`company_id`,`id`),'
            . 'UNIQUE KEY `uq_visibility_results_check` (`company_id`,`check_id`),'
            . 'KEY `idx_visibility_results_history` (`company_id`,`question_id`,`platform`,`model_name`,`detected_at`,`id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_result_company', $prefix)) . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($prefix . 'companies') . ' (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_result_check', $prefix)) . ' FOREIGN KEY (`company_id`,`check_id`) REFERENCES ' . $this->quote($prefix . 'geo_visibility_checks') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_result_question', $prefix)) . ' FOREIGN KEY (`company_id`,`question_id`) REFERENCES ' . $this->quote($prefix . 'questions') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function createVisibilityComparisons(PDO $pdo, string $prefix): void
    {
        $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'geo_visibility_comparisons') . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`company_id` BIGINT UNSIGNED NOT NULL,`previous_result_id` BIGINT UNSIGNED NOT NULL,`current_result_id` BIGINT UNSIGNED NOT NULL,'
            . '`trend` VARCHAR(20) NOT NULL,`answer_change_summary` TEXT NULL,`citation_change_json` JSON NOT NULL,`created_at` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id`),UNIQUE KEY `uq_visibility_comparisons_company_id` (`company_id`,`id`),'
            . 'UNIQUE KEY `uq_visibility_comparison_current` (`company_id`,`current_result_id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_comparison_previous', $prefix)) . ' FOREIGN KEY (`company_id`,`previous_result_id`) REFERENCES ' . $this->quote($prefix . 'geo_visibility_results') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('visibility_comparison_current', $prefix)) . ' FOREIGN KEY (`company_id`,`current_result_id`) REFERENCES ' . $this->quote($prefix . 'geo_visibility_results') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function createOptimizationSuggestions(PDO $pdo, string $prefix): void
    {
        $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'geo_optimization_suggestions') . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`company_id` BIGINT UNSIGNED NOT NULL,`result_id` BIGINT UNSIGNED NOT NULL,`comparison_id` BIGINT UNSIGNED NULL,`job_id` BIGINT UNSIGNED NULL,`ai_call_id` BIGINT UNSIGNED NULL,'
            . '`provider_code` VARCHAR(64) NULL,`model_name` VARCHAR(191) NULL,`provider_request_id` VARCHAR(191) NULL,`missed_question` TEXT NOT NULL,'
            . '`knowledge_gaps_json` JSON NOT NULL,`question_directions_json` JSON NOT NULL,`answer_directions_json` JSON NOT NULL,`content_directions_json` JSON NOT NULL,`generation_basis_json` JSON NOT NULL,'
            . "`status` VARCHAR(32) NOT NULL DEFAULT 'pending_review',`created_by` BIGINT UNSIGNED NOT NULL,`reviewed_by` BIGINT UNSIGNED NULL,`created_at` DATETIME NOT NULL,`reviewed_at` DATETIME NULL,"
            . 'PRIMARY KEY (`id`),UNIQUE KEY `uq_geo_suggestions_company_id` (`company_id`,`id`),'
            . 'UNIQUE KEY `uq_geo_suggestions_result` (`company_id`,`result_id`),'
            . 'KEY `idx_geo_suggestions_result_status` (`company_id`,`result_id`,`status`,`id`),'
            . 'CONSTRAINT ' . $this->quote($this->constraint('suggestion_result', $prefix)) . ' FOREIGN KEY (`company_id`,`result_id`) REFERENCES ' . $this->quote($prefix . 'geo_visibility_results') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('suggestion_comparison', $prefix)) . ' FOREIGN KEY (`company_id`,`comparison_id`) REFERENCES ' . $this->quote($prefix . 'geo_visibility_comparisons') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('suggestion_job', $prefix)) . ' FOREIGN KEY (`company_id`,`job_id`) REFERENCES ' . $this->quote($prefix . 'geo_async_jobs') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
            . 'CONSTRAINT ' . $this->quote($this->constraint('suggestion_call', $prefix)) . ' FOREIGN KEY (`company_id`,`ai_call_id`) REFERENCES ' . $this->quote($prefix . 'ai_calls') . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $statement->execute([$table, $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function dropConstraint(PDO $pdo, string $table, string $constraint): void
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
        $statement->execute([$table, $constraint]);
        if ((int) $statement->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP FOREIGN KEY ' . $this->quote($constraint));
        }
    }

    private function constraint(string $name, string $prefix): string
    {
        return 'fk_geo_t19_' . substr($name, 0, 32) . '_' . substr(sha1($prefix . $name), 0, 8);
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('T19 migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
