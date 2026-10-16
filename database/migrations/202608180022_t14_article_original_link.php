<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'publish_orders';
        if (!$this->tableExists($pdo, $table)) {
            throw new RuntimeException('T14 publish_orders table does not exist; cannot apply migration.');
        }
        $cols = $this->listColumns($pdo, $table);
        if (!in_array('original_link', $cols, true)) {
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($table)
                . ' ADD COLUMN `original_link` VARCHAR(2048) NULL AFTER `request_fingerprint`'
            );
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'publish_orders';
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        $cols = $this->listColumns($pdo, $table);
        if (in_array('original_link', $cols, true)) {
            $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP COLUMN `original_link`');
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

    private function listColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $stmt->execute(['table' => $table]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
