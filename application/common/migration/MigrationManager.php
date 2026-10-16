<?php

declare(strict_types=1);

namespace app\common\migration;

use PDO;
use RuntimeException;

final class MigrationManager
{
    private $pdo;
    private $migrationPath;
    private $prefix;
    private $repositoryTable;

    public function __construct(PDO $pdo, string $migrationPath, string $prefix)
    {
        if (!is_dir($migrationPath)) {
            throw new RuntimeException('Migration directory is missing.');
        }
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new RuntimeException('Database prefix contains unsupported characters.');
        }

        $this->pdo = $pdo;
        $this->migrationPath = rtrim($migrationPath, '/\\');
        $this->prefix = $prefix;
        $this->repositoryTable = $prefix . 'geo_migrations';
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function migrate(): array
    {
        $this->ensureRepository();
        $migrations = $this->discover();
        return $this->applyPending($migrations);
    }

    public function migrateThrough(string $target): array
    {
        $this->ensureRepository();
        $migrations = $this->discover();
        if (!isset($migrations[$target])) {
            throw new RuntimeException('Target migration file is missing: ' . $target);
        }
        $selected = [];
        foreach ($migrations as $id => $migration) {
            $selected[$id] = $migration;
            if ($id === $target) { break; }
        }
        return $this->applyPending($selected);
    }

    private function applyPending(array $migrations): array
    {
        $applied = array_flip($this->appliedIds());
        $pending = array_diff_key($migrations, $applied);
        if ($pending === []) {
            return [];
        }

        $batch = (int) $this->pdo
            ->query('SELECT COALESCE(MAX(`batch`), 0) FROM ' . $this->quote($this->repositoryTable))
            ->fetchColumn() + 1;
        $insert = $this->pdo->prepare(
            'INSERT INTO ' . $this->quote($this->repositoryTable)
            . ' (`migration`, `batch`, `migrated_at`) VALUES (:migration, :batch, :migrated_at)'
        );

        $completed = [];
        foreach ($pending as $id => $migration) {
            $migration->up($this->pdo, $this->prefix);
            $insert->execute([
                'migration' => $id,
                'batch' => $batch,
                'migrated_at' => date('Y-m-d H:i:s'),
            ]);
            $completed[] = $id;
        }

        return $completed;
    }

    public function rollback(int $batches = 1): array
    {
        if ($batches < 1) {
            throw new RuntimeException('Rollback batch count must be positive.');
        }

        $this->ensureRepository();
        $migrations = $this->discover();
        $batchRows = $this->pdo
            ->query(
                'SELECT DISTINCT `batch` FROM ' . $this->quote($this->repositoryTable)
                . ' ORDER BY `batch` DESC LIMIT ' . $batches
            )
            ->fetchAll(PDO::FETCH_COLUMN);

        $rolledBack = [];
        foreach ($batchRows as $batch) {
            $statement = $this->pdo->prepare(
                'SELECT `migration` FROM ' . $this->quote($this->repositoryTable)
                . ' WHERE `batch` = :batch ORDER BY `migration` DESC'
            );
            $statement->execute(['batch' => (int) $batch]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (!isset($migrations[$id])) {
                    throw new RuntimeException('Applied migration file is missing: ' . $id);
                }
                $migrations[$id]->down($this->pdo, $this->prefix);
                $delete = $this->pdo->prepare(
                    'DELETE FROM ' . $this->quote($this->repositoryTable)
                    . ' WHERE `migration` = :migration'
                );
                $delete->execute(['migration' => $id]);
                $rolledBack[] = $id;
            }
        }

        return $rolledBack;
    }

    public function refresh(): array
    {
        $this->ensureRepository();
        $batchCount = (int) $this->pdo
            ->query('SELECT COUNT(DISTINCT `batch`) FROM ' . $this->quote($this->repositoryTable))
            ->fetchColumn();
        if ($batchCount > 0) {
            $this->rollback($batchCount);
        }

        return $this->migrate();
    }

    public function status(): array
    {
        $this->ensureRepository();
        $applied = array_flip($this->appliedIds());
        $status = [];
        foreach ($this->discover() as $id => $migration) {
            $status[$id] = isset($applied[$id]) ? 'applied' : 'pending';
        }

        return $status;
    }

    private function ensureRepository(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->quote($this->repositoryTable) . ' ('
            . '`migration` VARCHAR(191) NOT NULL,'
            . '`batch` INT UNSIGNED NOT NULL,'
            . '`migrated_at` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`migration`),'
            . 'KEY `idx_batch` (`batch`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function discover(): array
    {
        $paths = glob($this->migrationPath . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($paths, SORT_STRING);
        $migrations = [];
        foreach ($paths as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME);
            $migration = require $path;
            if (!$migration instanceof Migration) {
                throw new RuntimeException('Migration must return a Migration instance: ' . $id);
            }
            $migrations[$id] = $migration;
        }

        return $migrations;
    }

    private function appliedIds(): array
    {
        return $this->pdo
            ->query(
                'SELECT `migration` FROM ' . $this->quote($this->repositoryTable)
                . ' ORDER BY `migration` ASC'
            )
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }
}
