<?php

declare(strict_types=1);

use app\common\migration\Migration;

// Materialize the per-catalog self-media/video filter fields into stored
// generated columns so enterprise media filters can use B-tree indexes.
return new class implements Migration {
    private const FIELDS = [
        'fans_range' => 'filter_fans_range',
        'read_range' => 'filter_read_range',
        'occupation' => 'filter_occupation',
        'special_projects' => 'filter_special_projects',
    ];

    public function up(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'media_resources';
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        $this->withRelaxedSqlMode($pdo, function () use ($pdo, $table): void {
            foreach (self::FIELDS as $jsonKey => $column) {
                if (!$this->columnExists($pdo, $table, $column)) {
                    $pdo->exec(
                        'ALTER TABLE ' . $this->quote($table)
                        . ' ADD COLUMN ' . $this->quote($column)
                        . ' VARCHAR(191) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT('
                        . '`attributes_json`, \'$.' . $jsonKey . '\'))) STORED'
                    );
                }
                $index = 'idx_media_filter_v2_' . $jsonKey;
                if (!$this->indexExists($pdo, $table, $index)) {
                    $pdo->exec(
                        'ALTER TABLE ' . $this->quote($table)
                        . ' ADD INDEX ' . $this->quote($index) . ' (`status`, ' . $this->quote($column) . ')'
                    );
                }
            }
        });
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'media_resources';
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        foreach (self::FIELDS as $jsonKey => $column) {
            $index = 'idx_media_filter_v2_' . $jsonKey;
            if ($this->indexExists($pdo, $table, $index)) {
                $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP INDEX ' . $this->quote($index));
            }
            if ($this->columnExists($pdo, $table, $column)) {
                $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP COLUMN ' . $this->quote($column));
            }
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
