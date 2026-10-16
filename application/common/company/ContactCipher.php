<?php

declare(strict_types=1);

namespace app\common\company;

interface ContactCipher
{
    public function encrypt(array $contacts, int $companyId): string;

    public function decrypt(string $ciphertext, int $companyId): array;
}
