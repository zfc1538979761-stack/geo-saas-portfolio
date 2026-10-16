<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    private const RULES = [
        'internal/monitor/index' => 'View health monitor',
        'internal/monitor/health' => 'Read internal health report',
        'internal/monitor/check' => 'Run health and alert check',
        'internal/monitor/alerts' => 'View alert events',
        'internal/monitor/ack' => 'Acknowledge alert events',
        'internal/webhook/index' => 'View persisted callbacks',
        'internal/webhook/replay' => 'Replay persisted callbacks',
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
                'remark' => 'T17 internal monitor/webhook permission',
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
