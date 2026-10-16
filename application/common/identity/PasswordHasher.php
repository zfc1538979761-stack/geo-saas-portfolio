<?php

declare(strict_types=1);

namespace app\common\identity;

use RuntimeException;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return $hash === '' || password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
