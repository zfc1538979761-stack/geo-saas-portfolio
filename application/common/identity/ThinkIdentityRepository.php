<?php

declare(strict_types=1);

namespace app\common\identity;

use think\Config;
use think\Db;

final class ThinkIdentityRepository implements IdentityRepository
{
    public function findByAccount(string $account): ?array
    {
        return $this->query()
            ->where('u.username', trim($account))
            ->where('u.company_id', '>', 0)
            ->find() ?: null;
    }

    public function findById(int $userId): ?array
    {
        return $this->query()
            ->where('u.id', $userId)
            ->where('u.company_id', '>', 0)
            ->find() ?: null;
    }

    public function recordSuccessfulLogin(int $userId, string $ip, int $occurredAt): void
    {
        $current = Db::table($this->table('user'))
            ->where('id', $userId)
            ->field('logintime')
            ->find();
        Db::table($this->table('user'))
            ->where('id', $userId)
            ->update([
                'prevtime' => $current['logintime'] ?? null,
                'logintime' => $occurredAt,
                'loginip' => $ip,
                'loginfailure' => 0,
                'loginfailuretime' => null,
                'updatetime' => $occurredAt,
            ]);
    }

    public function updatePassword(int $userId, string $passwordHash, int $updatedAt): void
    {
        Db::table($this->table('user'))
            ->where('id', $userId)
            ->update([
                'password_hash' => $passwordHash,
                'loginfailure' => 0,
                'loginfailuretime' => null,
                'updatetime' => $updatedAt,
            ]);
    }

    private function query()
    {
        return Db::table($this->table('user'))
            ->alias('u')
            ->join($this->table('companies') . ' c', 'c.id=u.company_id', 'INNER')
            ->field([
                'u.id',
                'u.company_id',
                'u.username',
                'u.password_hash',
                'u.role',
                'u.status',
                'u.createtime',
                'u.updatetime',
                'c.name' => 'company_name',
                'c.status' => 'company_status',
            ]);
    }

    private function table(string $name): string
    {
        return (string) Config::get('database.prefix') . $name;
    }
}
