<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    private const RULES = [
        'internal/index/index' => 'Internal maintenance dashboard',
        'internal/ai_config/index' => 'View AI configuration status',
        'internal/ai_config/replace' => 'Replace AI API key',
        'internal/supplier_config/index' => 'View supplier configuration status',
        'internal/supplier_config/replace' => 'Replace supplier credentials',
        'internal/media_sync/index' => 'View media sync status',
        'internal/media_sync/trigger' => 'Trigger media sync',
        'internal/company/index' => 'View companies',
        'internal/company/status' => 'Change company status',
        'internal/wallet/index' => 'View wallet balances',
        'internal/wallet/credit' => 'Manual wallet credit',
        'internal/wallet/debit' => 'Manual wallet debit',
        'internal/job/index' => 'View failed jobs',
        'internal/job/replay' => 'Replay failed jobs',
        'internal/order/index' => 'View manual review orders',
        'internal/order/reprocess' => 'Reprocess manual review orders',
        'internal/audit/index' => 'View audit logs',
        'internal/log/index' => 'View runtime logs',
        'internal/log/show' => 'Read runtime log content',
        'tenant.scope.bypass' => 'Internal tenant scope bypass',
    ];

    public function up(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'auth_rule';
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        $now = time();
        foreach (self::RULES as $name => $title) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $this->quote($table) . ' WHERE `name`=:name');
            $stmt->execute(['name' => $name]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO ' . $this->quote($table)
                . ' (`type`,`pid`,`name`,`title`,`icon`,`ismenu`,`condition`,`remark`,`status`,`createtime`,`updatetime`,`weigh`)'
                . ' VALUES (:type,:pid,:name,:title,:icon,:ismenu,:condition,:remark,:status,:ct,:ut,:weigh)'
            )->execute([
                'type' => 2,
                'pid' => 0,
                'name' => $name,
                'title' => $title,
                'icon' => '',
                'ismenu' => 0,
                'condition' => '',
                'remark' => 'T16 internal maintenance permission',
                'status' => 'normal',
                'ct' => $now,
                'ut' => $now,
                'weigh' => 0,
            ]);
        }
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $table = $prefix . 'auth_rule';
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        $stmt = $pdo->prepare('DELETE FROM ' . $this->quote($table) . ' WHERE `name`=:name');
        foreach (array_keys(self::RULES) as $name) {
            $stmt->execute(['name' => $name]);
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

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
