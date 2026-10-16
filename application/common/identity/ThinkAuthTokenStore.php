<?php

declare(strict_types=1);

namespace app\common\identity;

use think\Config;
use think\Db;

final class ThinkAuthTokenStore implements AuthTokenStore
{
    private const PURPOSE = 'enterprise_owner';

    public function put(string $token, int $userId, int $ttlSeconds): void
    {
        $now = time();
        Db::table($this->table())->insert([
            'token' => $this->hashToken($token),
            'user_id' => $userId,
            'purpose' => self::PURPOSE,
            'createtime' => $now,
            'expiretime' => $now + $ttlSeconds,
        ]);
    }

    public function get(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $row = Db::table($this->table())
            ->where('token', $this->hashToken($token))
            ->where('purpose', self::PURPOSE)
            ->find();
        if (!$row) {
            return null;
        }
        if ((int) $row['expiretime'] <= time()) {
            $this->delete($token);
            return null;
        }

        return [
            'user_id' => (int) $row['user_id'],
            'expiretime' => (int) $row['expiretime'],
        ];
    }

    public function delete(string $token): void
    {
        if ($token !== '') {
            Db::table($this->table())
                ->where('token', $this->hashToken($token))
                ->where('purpose', self::PURPOSE)
                ->delete();
        }
    }

    public function clearUser(int $userId): void
    {
        Db::table($this->table())
            ->where('user_id', $userId)
            ->where('purpose', self::PURPOSE)
            ->delete();
    }

    private function hashToken(string $token): string
    {
        $config = Config::get('token');
        $algorithm = (string) ($config['hashalgo'] ?? '');
        $key = (string) ($config['key'] ?? '');
        if ($algorithm === '' || $key === '' || !in_array($algorithm, hash_hmac_algos(), true)) {
            throw new \RuntimeException('Token hashing configuration is invalid.');
        }
        return hash_hmac($algorithm, $token, $key);
    }

    private function table(): string
    {
        return (string) Config::get('database.prefix') . 'user_token';
    }
}
