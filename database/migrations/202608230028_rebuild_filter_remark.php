<?php

declare(strict_types=1);

use app\common\migration\Migration;

// Rebuild filter_remark with a truncated expression so long supplier remarks
// do not fail media sync. The full remark stays intact in attributes_json.
return new class implements Migration {
    private const COLUMN = 'filter_remark';
    private const INDEX = 'idx_media_filter_v3_remark';

    public function up(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'media_resources';
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        $this->withRelaxedSqlMode($pdo, function () use ($pdo, $table): void {
            if ($this->indexExists($pdo, $table, self::INDEX)) {
                $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP INDEX ' . $this->quote(self::INDEX));
            }
            if ($this->columnExists($pdo, $table, self::COLUMN)) {
                $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP COLUMN ' . $this->quote(self::COLUMN));
            }
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($table)
                . ' ADD COLUMN ' . $this->quote(self::COLUMN)
                . " VARCHAR(191) GENERATED ALWAYS AS (LEFT(JSON_UNQUOTE(JSON_EXTRACT("
                . "`attributes_json`, '$.remark')), 191)) STORED"
            );
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($table)
                . ' ADD INDEX ' . $this->quote(self::INDEX) . ' (`status`, ' . $this->quote(self::COLUMN) . ')'
            );
        });
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'media_resources';
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        if ($this->indexExists($pdo, $table, self::INDEX)) {
            $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP INDEX ' . $this->quote(self::INDEX));
        }
        if ($this->columnExists($pdo, $table, self::COLUMN)) {
            $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP COLUMN ' . $this->quote(self::COLUMN));
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function withRelaxedSqlMode(PDO $pdo, callable $operation): void
    {
        $original = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        try {
            $operation();
        } finally {
            if ($original !== '') {
                $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($original));
            }
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index'
        );
        $stmt->execute(['table' => $table, 'index' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
