<?php

declare(strict_types=1);

namespace app\common\identity;

interface AuthTokenStore
{
    public function put(string $token, int $userId, int $ttlSeconds): void;

    public function get(string $token): ?array;

    public function delete(string $token): void;

    public function clearUser(int $userId): void;
}
