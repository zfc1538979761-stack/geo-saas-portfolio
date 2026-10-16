<?php

declare(strict_types=1);

use app\common\migration\Migration;

return new class implements Migration {
    public function up(PDO $pdo, string $prefix): void
    {
        $authRule = $prefix . 'auth_rule';
        if (!$this->tableExists($pdo, $authRule)) {
            return;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $this->quote($authRule) . ' WHERE name=:name');
        $stmt->execute(['name' => 'wallet.adjust']);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO ' . $this->quote($authRule)
            . ' (type,pid,name,title,icon,ismenu,' . $this->quote('condition') . ',remark,status,createtime,updatetime,weigh)'
            . ' VALUES (:type,:pid,:name,:title,:icon,:ismenu,:condition,:remark,:status,:ct,:ut,:weigh)'
        )->execute([
            'type' => 2,
            'pid' => 0,
            'name' => 'wallet.adjust',
            'title' => 'Wallet Manual Adjust',
            'icon' => '',
            'ismenu' => 0,
            'condition' => '',
            'remark' => 'Permission for manual wallet balance adjustments',
            'status' => 'normal',
            'ct' => time(),
            'ut' => time(),
            'weigh' => 0,
        ]);
    }

    public function down(PDO $pdo, string $prefix): void
    {
        $authRule = $prefix . 'auth_rule';
        if ($this->tableExists($pdo, $authRule)) {
            $pdo->prepare('DELETE FROM ' . $this->quote($authRule) . ' WHERE name=:name')
                ->execute(['name' => 'wallet.adjust']);
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

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Migration identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
};
