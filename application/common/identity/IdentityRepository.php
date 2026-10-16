<?php

declare(strict_types=1);

namespace app\common\identity;

interface IdentityRepository
{
    public function findByAccount(string $account): ?array;

    public function findById(int $userId): ?array;

    public function recordSuccessfulLogin(int $userId, string $ip, int $occurredAt): void;

    public function updatePassword(int $userId, string $passwordHash, int $updatedAt): void;
}
