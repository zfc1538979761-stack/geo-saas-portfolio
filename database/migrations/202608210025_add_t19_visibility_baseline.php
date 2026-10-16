<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $plans = $prefix . 'geo_visibility_plans';
        $results = $prefix . 'geo_visibility_results';
        if (!$this->tableExists($pdo, $plans) || !$this->tableExists($pdo, $results)) {
            throw new RuntimeException('T19 visibility tables must exist before the baseline migration.');
        }
        if ($this->columnExists($pdo, $plans, 'baseline_result_id')) {
            throw new RuntimeException('T19 baseline column exists outside migration history.');
        }

        $pdo->exec(
            'ALTER TABLE ' . $this->quote($plans)
            . ' ADD COLUMN `baseline_result_id` BIGINT UNSIGNED NULL AFTER `question_id`,'
            . ' ADD KEY `idx_visibility_plan_baseline` (`company_id`,`baseline_result_id`),'
            . ' ADD CONSTRAINT ' . $this->quote($this->constraint($prefix))
            . ' FOREIGN KEY (`company_id`,`baseline_result_id`) REFERENCES ' . $this->quote($results)
            . ' (`company_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
        );
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $plans = $prefix . 'geo_visibility_plans';
        if (!$this->columnExists($pdo, $plans, 'baseline_result_id')) { return; }
        $pdo->exec(
            'ALTER TABLE ' . $this->quote($plans)
            . ' DROP FOREIGN KEY ' . $this->quote($this->constraint($prefix))
            . ', DROP INDEX `idx_visibility_plan_baseline`'
            . ', DROP COLUMN `baseline_result_id`'
        );
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

    private function constraint(string $prefix): string
    {
        return 'fk_geo_t19_plan_baseline_' . substr(sha1($prefix . 'visibility_plan_baseline'), 0, 8);
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('T19 baseline migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
