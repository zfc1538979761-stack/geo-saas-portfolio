<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $items = $prefix . "publish_order_items";

        if (!$this->tableExists($pdo, $items)) {
            throw new RuntimeException("T13: publish_order_items table does not exist; cannot apply T13 migration.");
        }

        $cols = $this->listColumns($pdo, $items);

        $additions = [
            "ADD COLUMN `last_synced_at` DATETIME NULL AFTER `supplier_raw_status`" => "last_synced_at",
            "ADD COLUMN `supplier_sync_raw` TEXT NULL AFTER `last_synced_at`" => "supplier_sync_raw",
        ];

        foreach ($additions as $ddl => $col) {
            if (!in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE " . $this->quote($items) . " " . $ddl);
            }
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $items = $prefix . "publish_order_items";
        if (!$this->tableExists($pdo, $items)) {
            return;
        }
        $cols = $this->listColumns($pdo, $items);
        $removals = ["supplier_sync_raw", "last_synced_at"];
        foreach ($removals as $col) {
            if (in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE " . $this->quote($items) . " DROP COLUMN " . $this->quote($col));
            }
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table"
        );
        $stmt->execute(["table" => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function listColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table"
        );
        $stmt->execute(["table" => $table]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), "COLUMN_NAME");
    }

    private function quote(string $identifier): string
    {
        if (preg_match("/^[A-Za-z0-9_]+$/D", $identifier) !== 1) {
            throw new RuntimeException("Migration identifier is invalid.");
        }
        return "`" . $identifier . "`";
    }
};