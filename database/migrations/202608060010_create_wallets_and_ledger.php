<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $wallets = $prefix . 'wallets';
        $ledgerEntries = $prefix . 'ledger_entries';

        foreach ([$wallets, $ledgerEntries] as $table) {
            if ($this->tableExists($pdo, $table)) {
                throw new RuntimeException('T10 table exists outside migration history: ' . $table);
            }
        }

        $created = [];
        try {
            $pdo->exec(
                'CREATE TABLE ' . $this->quote($wallets) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`currency` VARCHAR(3) NOT NULL DEFAULT \'CNY\','
                . '`available_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`frozen_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`version` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_wallet_company` (`company_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $wallets;

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($ledgerEntries) . ' ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`wallet_id` BIGINT UNSIGNED NOT NULL,'
                . '`company_id` BIGINT UNSIGNED NOT NULL,'
                . '`currency` VARCHAR(3) NOT NULL DEFAULT \'CNY\','
                . '`entry_type` VARCHAR(30) NOT NULL,'
                . '`biz_type` VARCHAR(50) DEFAULT NULL,'
                . '`biz_id` VARCHAR(191) DEFAULT NULL,'
                . '`idempotency_key` VARCHAR(191) NOT NULL,'
                . '`idempotency_fingerprint` VARCHAR(64) DEFAULT NULL,'
                . '`amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`available_delta` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`frozen_delta` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`available_after` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`frozen_after` DECIMAL(18,2) NOT NULL DEFAULT 0.00,'
                . '`actor_id` BIGINT UNSIGNED DEFAULT NULL,'
                . '`actor_type` VARCHAR(20) DEFAULT NULL,'
                . '`reason` VARCHAR(500) DEFAULT NULL,'
                . '`metadata_json` JSON DEFAULT NULL,'
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_ledger_idempotency` (`company_id`,`idempotency_key`),'
                . 'KEY `idx_ledger_company_paginate` (`company_id`,`id`),'
                . 'KEY `idx_ledger_biz_reconcile` (`company_id`,`biz_type`,`biz_id`,`id`),'
                . 'KEY `idx_ledger_wallet` (`wallet_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $created[] = $ledgerEntries;

            $companies = $prefix . 'companies';
            if ($this->tableExists($pdo, $companies)) {
                $pdo->exec(
                    'INSERT INTO ' . $this->quote($wallets)
                    . ' (`company_id`,`currency`,`available_balance`,`frozen_balance`,`version`)'
                    . ' SELECT c.`id`,\'CNY\',0.00,0.00,0'
                    . ' FROM ' . $this->quote($companies) . ' c'
                    . ' WHERE NOT EXISTS ('
                    . '  SELECT 1 FROM ' . $this->quote($wallets) . ' w WHERE w.`company_id`=c.`id`'
                    . ' )'
                );
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($created) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($table));
            }
            throw $exception;
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'ledger_entries'));
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($prefix . 'wallets'));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
