<?php

declare(strict_types=1);

use app\common\migration\Migration;

// @tenant-resource oss_file_indexes
// @tenant-resource knowledge_items
// @tenant-resource prompt_templates
// @tenant-resource articles
// @tenant-resource article_versions
// @tenant-resource article_version_knowledge
return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $companies = $prefix . 'companies';
        $keywords = $prefix . 'keywords';
        $questions = $prefix . 'questions';
        $files = $prefix . 'oss_file_indexes';
        $knowledge = $prefix . 'knowledge_items';
        $prompts = $prefix . 'prompt_templates';
        $articles = $prefix . 'articles';
        $versions = $prefix . 'article_versions';
        $versionKnowledge = $prefix . 'article_version_knowledge';
        foreach ([$companies, $keywords, $questions] as $required) {
            if (!$this->tableExists($pdo, $required)) {
                throw new RuntimeException('T06 requires the T02 and T05 content tables.');
            }
        }
        foreach ([$files, $knowledge, $prompts, $articles, $versions, $versionKnowledge] as $table) {
            if ($this->tableExists($pdo, $table)) {
                throw new RuntimeException('T06 table exists outside migration history.');
            }
        }
        if ($this->indexExists($pdo, $questions, 'uq_questions_company_id_keyword')) {
            throw new RuntimeException('T06 question relationship key exists outside migration history.');
        }

        $created = [];
        $questionIndexAdded = false;
        try {
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($questions)
                . ' ADD UNIQUE KEY `uq_questions_company_id_keyword` (`company_id`,`id`,`keyword_id`)'
            );
            $questionIndexAdded = true;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($files) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . "`provider` VARCHAR(32) NOT NULL DEFAULT 'media_market',"
                . '`remote_file_id` VARCHAR(191) NOT NULL,'
                . '`object_key` VARCHAR(512) NOT NULL,'
                . '`location_index` VARCHAR(1024) NOT NULL,'
                . '`original_name` VARCHAR(255) NOT NULL,'
                . '`safe_name` VARCHAR(191) NOT NULL,'
                . '`extension` VARCHAR(10) NOT NULL,'
                . '`declared_mime` VARCHAR(127) NOT NULL,'
                . '`detected_mime` VARCHAR(127) NOT NULL,'
                . '`size_bytes` BIGINT UNSIGNED NOT NULL,'
                . '`sha256` CHAR(64) NOT NULL,'
                . "`status` VARCHAR(20) NOT NULL DEFAULT 'normal',"
                . '`metadata_json` JSON NOT NULL,'
                . '`created_by` BIGINT UNSIGNED NOT NULL,'
                . '`updated_by` BIGINT UNSIGNED NOT NULL,'
                . '`createtime` BIGINT UNSIGNED NOT NULL,'
                . '`updatetime` BIGINT UNSIGNED NOT NULL,'
                . '`deletetime` BIGINT UNSIGNED NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_files_company_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_files_company_remote` (`company_id`,`remote_file_id`),'
                . 'UNIQUE KEY `uq_files_company_sha256` (`company_id`,`sha256`),'
                . 'KEY `idx_files_company_status_created` (`company_id`,`status`,`createtime`,`id`),'
                . 'KEY `idx_files_company_type_created` (`company_id`,`extension`,`createtime`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('file_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $files;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($knowledge) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`type` VARCHAR(20) NOT NULL,'
                . '`title` VARCHAR(191) NOT NULL,'
                . '`content` LONGTEXT NULL,'
                . '`oss_file_index_id` BIGINT UNSIGNED NULL,'
                . "`status` VARCHAR(20) NOT NULL DEFAULT 'normal',"
                . '`created_by` BIGINT UNSIGNED NOT NULL,'
                . '`updated_by` BIGINT UNSIGNED NOT NULL,'
                . '`createtime` BIGINT UNSIGNED NOT NULL,'
                . '`updatetime` BIGINT UNSIGNED NOT NULL,'
                . '`deletetime` BIGINT UNSIGNED NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_knowledge_company_id` (`company_id`,`id`),'
                . 'KEY `idx_knowledge_company_type_status_created` (`company_id`,`type`,`status`,`createtime`,`id`),'
                . 'KEY `idx_knowledge_company_status_created` (`company_id`,`status`,`createtime`,`id`),'
                . 'KEY `idx_knowledge_company_file` (`company_id`,`oss_file_index_id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('knowledge_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('knowledge_file', $prefix))
                . ' FOREIGN KEY (`company_id`,`oss_file_index_id`) REFERENCES ' . $this->quote($files)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $knowledge;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($prompts) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`logical_key` CHAR(32) NOT NULL,'
                . '`name` VARCHAR(191) NOT NULL,'
                . '`scene` VARCHAR(64) NOT NULL,'
                . '`version_no` INT UNSIGNED NOT NULL,'
                . '`content` LONGTEXT NOT NULL,'
                . "`status` VARCHAR(20) NOT NULL DEFAULT 'normal',"
                . '`created_by` BIGINT UNSIGNED NOT NULL,'
                . '`updated_by` BIGINT UNSIGNED NOT NULL,'
                . '`createtime` BIGINT UNSIGNED NOT NULL,'
                . '`updatetime` BIGINT UNSIGNED NOT NULL,'
                . '`deletetime` BIGINT UNSIGNED NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_prompts_company_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_prompts_company_logical_version` (`company_id`,`logical_key`,`version_no`),'
                . 'KEY `idx_prompts_company_status_created` (`company_id`,`status`,`createtime`,`id`),'
                . 'KEY `idx_prompts_company_scene_status` (`company_id`,`scene`,`status`,`createtime`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('prompt_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $prompts;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($articles) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`keyword_id` BIGINT UNSIGNED NOT NULL,'
                . '`question_id` BIGINT UNSIGNED NOT NULL,'
                . '`title` VARCHAR(191) NOT NULL,'
                . '`current_version_id` BIGINT UNSIGNED NULL,'
                . "`status` VARCHAR(20) NOT NULL DEFAULT 'draft',"
                . '`created_by` BIGINT UNSIGNED NOT NULL,'
                . '`updated_by` BIGINT UNSIGNED NOT NULL,'
                . '`createtime` BIGINT UNSIGNED NOT NULL,'
                . '`updatetime` BIGINT UNSIGNED NOT NULL,'
                . '`deletetime` BIGINT UNSIGNED NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_articles_company_id` (`company_id`,`id`),'
                . 'KEY `idx_articles_current_integrity` (`company_id`,`id`,`current_version_id`),'
                . 'KEY `idx_articles_company_status_updated` (`company_id`,`status`,`updatetime`,`id`),'
                . 'KEY `idx_articles_company_keyword_question` (`company_id`,`keyword_id`,`question_id`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('article_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('article_keyword', $prefix))
                . ' FOREIGN KEY (`company_id`,`keyword_id`) REFERENCES ' . $this->quote($keywords)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('article_question_keyword', $prefix))
                . ' FOREIGN KEY (`company_id`,`question_id`,`keyword_id`) REFERENCES ' . $this->quote($questions)
                . ' (`company_id`,`id`,`keyword_id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $articles;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($versions) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`article_id` BIGINT UNSIGNED NOT NULL,'
                . '`version_no` INT UNSIGNED NOT NULL,'
                . '`title_snapshot` VARCHAR(191) NOT NULL,'
                . '`content` LONGTEXT NOT NULL,'
                . "`source` VARCHAR(20) NOT NULL DEFAULT 'manual',"
                . '`prompt_template_id` BIGINT UNSIGNED NOT NULL,'
                . '`created_by` BIGINT UNSIGNED NOT NULL,'
                . '`createtime` BIGINT UNSIGNED NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_versions_company_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_versions_article_number` (`company_id`,`article_id`,`version_no`),'
                . 'UNIQUE KEY `uq_versions_current_integrity` (`company_id`,`article_id`,`id`),'
                . 'KEY `idx_versions_company_article_created` (`company_id`,`article_id`,`createtime`,`id`),'
                . 'KEY `idx_versions_company_prompt` (`company_id`,`prompt_template_id`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('version_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('version_article', $prefix))
                . ' FOREIGN KEY (`company_id`,`article_id`) REFERENCES ' . $this->quote($articles)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('version_prompt', $prefix))
                . ' FOREIGN KEY (`company_id`,`prompt_template_id`) REFERENCES ' . $this->quote($prompts)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $versions;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($versionKnowledge) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`article_version_id` BIGINT UNSIGNED NOT NULL,'
                . '`knowledge_item_id` BIGINT UNSIGNED NOT NULL,'
                . '`knowledge_snapshot_json` JSON NOT NULL,'
                . '`createtime` BIGINT UNSIGNED NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_version_knowledge_company_id` (`company_id`,`id`),'
                . 'UNIQUE KEY `uq_version_knowledge_relation` (`company_id`,`article_version_id`,`knowledge_item_id`),'
                . 'KEY `idx_version_knowledge_company_version` (`company_id`,`article_version_id`,`id`),'
                . 'KEY `idx_version_knowledge_company_item` (`company_id`,`knowledge_item_id`,`id`),'
                . 'CONSTRAINT ' . $this->quote($this->constraint('version_knowledge_company', $prefix))
                . ' FOREIGN KEY (`company_id`) REFERENCES ' . $this->quote($companies) . ' (`id`)'
                . ' ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('version_knowledge_version', $prefix))
                . ' FOREIGN KEY (`company_id`,`article_version_id`) REFERENCES ' . $this->quote($versions)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,'
                . 'CONSTRAINT ' . $this->quote($this->constraint('version_knowledge_item', $prefix))
                . ' FOREIGN KEY (`company_id`,`knowledge_item_id`) REFERENCES ' . $this->quote($knowledge)
                . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $versionKnowledge;

            $pdo->exec(
                'ALTER TABLE ' . $this->quote($articles)
                . ' ADD CONSTRAINT ' . $this->quote($this->constraint('article_current_version', $prefix))
                . ' FOREIGN KEY (`company_id`,`id`,`current_version_id`) REFERENCES ' . $this->quote($versions)
                . ' (`company_id`,`article_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
            );
        } catch (Throwable $exception) {
            if ($this->tableExists($pdo, $articles)
                && $this->constraintExists($pdo, $articles, $this->constraint('article_current_version', $prefix))) {
                $pdo->exec(
                    'ALTER TABLE ' . $this->quote($articles) . ' DROP FOREIGN KEY '
                    . $this->quote($this->constraint('article_current_version', $prefix))
                );
            }
            foreach (array_reverse($created) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($table));
            }
            if ($questionIndexAdded && $this->indexExists($pdo, $questions, 'uq_questions_company_id_keyword')) {
                $pdo->exec('ALTER TABLE ' . $this->quote($questions) . ' DROP INDEX `uq_questions_company_id_keyword`');
            }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $questions = $prefix . 'questions';
        $articles = $prefix . 'articles';
        if ($this->tableExists($pdo, $articles)
            && $this->constraintExists($pdo, $articles, $this->constraint('article_current_version', $prefix))) {
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($articles) . ' DROP FOREIGN KEY '
                . $this->quote($this->constraint('article_current_version', $prefix))
            );
        }
        foreach (['article_version_knowledge', 'article_versions', 'articles', 'prompt_templates', 'knowledge_items', 'oss_file_indexes'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . $table));
        }
        if ($this->tableExists($pdo, $questions)
            && $this->indexExists($pdo, $questions, 'uq_questions_company_id_keyword')) {
            $pdo->exec('ALTER TABLE ' . $this->quote($questions) . ' DROP INDEX `uq_questions_company_id_keyword`');
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $statement->execute(['table' => $table]);
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

    private function constraintExists(PDO $pdo, string $table, string $constraint): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND CONSTRAINT_NAME=:constraint'
        );
        $statement->execute(['table' => $table, 'constraint' => $constraint]);
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
