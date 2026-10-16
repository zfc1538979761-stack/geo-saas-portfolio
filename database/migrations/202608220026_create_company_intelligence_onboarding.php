<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @tenant-resource company_discovery_runs
// @tenant-resource company_discovery_candidates
// @tenant-resource company_candidate_sources
// @tenant-resource company_profile_drafts
// @tenant-resource company_profile_draft_facts
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        foreach (['companies', 'knowledge_items'] as $required) {
            if (!$this->tableExists($pdo, $prefix . $required)) {
                throw new RuntimeException('T19 company intelligence requires company and knowledge tables.');
            }
        }
        $tables = [
            'company_discovery_runs', 'company_discovery_candidates', 'company_candidate_sources',
            'company_profile_drafts', 'company_profile_draft_facts',
        ];
        foreach ($tables as $table) {
            if ($this->tableExists($pdo, $prefix . $table)) {
                throw new RuntimeException('T19 company intelligence table exists outside migration history.');
            }
        }

        $created = [];
        try {
            $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'company_discovery_runs') . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`company_name` VARCHAR(191) NOT NULL,'
                . '`region` VARCHAR(191) NOT NULL,'
                . '`website` VARCHAR(2048) NULL,'
                . '`unified_social_credit_code` CHAR(18) NULL,'
                . '`input_snapshot_json` JSON NOT NULL,'
                . '`provider_code` VARCHAR(64) NOT NULL,'
                . '`provider_request_id` VARCHAR(191) NULL,'
                . "`status` VARCHAR(32) NOT NULL DEFAULT 'running',"
                . '`error_category` VARCHAR(64) NULL,'
                . '`external_code` VARCHAR(64) NULL,'
                . '`network_executed` TINYINT(1) NOT NULL DEFAULT 0,'
                . '`candidate_count` INT UNSIGNED NOT NULL DEFAULT 0,'
                . '`created_by` BIGINT UNSIGNED NOT NULL,'
                . '`created_at` DATETIME(6) NOT NULL,'
                . '`completed_at` DATETIME(6) NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_company_discovery_runs_tenant_id` (`company_id`,`id`),'
                . 'KEY `idx_company_discovery_runs_status` (`company_id`,`status`,`created_at`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('discovery_run_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($prefix . 'companies') . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $created[] = 'company_discovery_runs';

            $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'company_discovery_candidates') . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`run_id` BIGINT UNSIGNED NOT NULL,'
                . '`external_key` VARCHAR(191) NOT NULL,'
                . '`name` VARCHAR(191) NOT NULL,'
                . '`region` VARCHAR(191) NOT NULL,'
                . '`website` VARCHAR(2048) NULL,'
                . '`unified_social_credit_code` CHAR(18) NULL,'
                . '`public_summary` TEXT NULL,'
                . "`status` VARCHAR(32) NOT NULL DEFAULT 'discovered',"
                . '`confirmed_by` BIGINT UNSIGNED NULL,'
                . '`confirmed_at` DATETIME(6) NULL,'
                . '`created_at` DATETIME(6) NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_company_candidates_tenant_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_company_candidates_external` (`company_id`,`run_id`,`external_key`),'
                . 'KEY `idx_company_candidates_run_status` (`company_id`,`run_id`,`status`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('candidate_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($prefix . 'companies') . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('candidate_run', $prefix))
                . ' FOREIGN KEY (`company_id`,`run_id`) REFERENCES ' . $this->quote($prefix . 'company_discovery_runs')
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $created[] = 'company_discovery_candidates';

            $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'company_candidate_sources') . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`candidate_id` BIGINT UNSIGNED NOT NULL,'
                . '`source_key` VARCHAR(191) NOT NULL,'
                . '`source_url` VARCHAR(2048) NOT NULL,'
                . '`source_title` VARCHAR(512) NOT NULL,'
                . '`public_excerpt` TEXT NULL,'
                . '`published_at` VARCHAR(64) NULL,'
                . '`fetched_at` DATETIME(6) NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_company_sources_tenant_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_company_sources_candidate_key` (`company_id`,`candidate_id`,`source_key`),'
                . 'KEY `idx_company_sources_candidate` (`company_id`,`candidate_id`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('source_candidate', $prefix))
                . ' FOREIGN KEY (`company_id`,`candidate_id`) REFERENCES ' . $this->quote($prefix . 'company_discovery_candidates')
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $created[] = 'company_candidate_sources';

            $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'company_profile_drafts') . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`candidate_id` BIGINT UNSIGNED NOT NULL,'
                . "`status` VARCHAR(32) NOT NULL DEFAULT 'discovered',"
                . '`profile_baseline_json` JSON NOT NULL,'
                . '`draft_snapshot_json` JSON NOT NULL,'
                . '`inference_json` JSON NOT NULL,'
                . '`ai_provider_code` VARCHAR(64) NULL,'
                . '`ai_model_name` VARCHAR(191) NULL,'
                . '`ai_request_id` VARCHAR(191) NULL,'
                . '`confirmed_snapshot_json` JSON NULL,'
                . '`confirmed_by` BIGINT UNSIGNED NULL,'
                . '`confirmed_at` DATETIME(6) NULL,'
                . '`knowledge_item_id` BIGINT UNSIGNED NULL,'
                . '`created_at` DATETIME(6) NOT NULL,'
                . '`updated_at` DATETIME(6) NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_company_drafts_tenant_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_company_drafts_candidate` (`company_id`,`candidate_id`),'
                . 'KEY `idx_company_drafts_status` (`company_id`,`status`,`updated_at`,`id`),'
                . 'KEY `idx_company_drafts_knowledge` (`company_id`,`knowledge_item_id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('draft_candidate', $prefix))
                . ' FOREIGN KEY (`company_id`,`candidate_id`) REFERENCES ' . $this->quote($prefix . 'company_discovery_candidates')
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('draft_knowledge', $prefix))
                . ' FOREIGN KEY (`company_id`,`knowledge_item_id`) REFERENCES ' . $this->quote($prefix . 'knowledge_items')
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $created[] = 'company_profile_drafts';

            $pdo->exec('CREATE TABLE ' . $this->quote($prefix . 'company_profile_draft_facts') . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`draft_id` BIGINT UNSIGNED NOT NULL,'
                . '`field_key` VARCHAR(64) NOT NULL,'
                . '`value_json` JSON NOT NULL,'
                . "`fact_type` VARCHAR(32) NOT NULL DEFAULT 'source_fact',"
                . "`status` VARCHAR(32) NOT NULL DEFAULT 'discovered',"
                . '`source_ids_json` JSON NOT NULL,'
                . '`created_at` DATETIME(6) NOT NULL,'
                . '`updated_at` DATETIME(6) NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_company_draft_facts_tenant_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_company_draft_facts_field` (`company_id`,`draft_id`,`field_key`),'
                . 'KEY `idx_company_draft_facts_status` (`company_id`,`draft_id`,`status`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('fact_draft', $prefix))
                . ' FOREIGN KEY (`company_id`,`draft_id`) REFERENCES ' . $this->quote($prefix . 'company_profile_drafts')
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $created[] = 'company_profile_draft_facts';
        } catch (Throwable $exception) {
            foreach (array_reverse($created) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . $table));
            }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        foreach (['company_profile_draft_facts', 'company_profile_drafts', 'company_candidate_sources', 'company_discovery_candidates', 'company_discovery_runs'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . $table));
        }
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
