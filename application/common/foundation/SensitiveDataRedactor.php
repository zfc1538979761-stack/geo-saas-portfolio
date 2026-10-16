<?php

declare(strict_types=1);

namespace app\common\foundation;

final class SensitiveDataRedactor
{
    private const SECRET_KEY_PATTERN =
        '/(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?code|authorization|cookie|'
        . 'private[_-]?key|(?:^|[_-])(?:mobile|phone|email|contacts?[_-]ciphertext)(?:$|[_-]))/i';

    public static function redact($value, ?string $key = null)
    {
        if ($key !== null && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = self::redact($childValue, (string) $childKey);
            }
            return $redacted;
        }

        if (is_object($value)) {
            return self::redact(get_object_vars($value));
        }

        if (is_string($value)) {
            $value = preg_replace(
                '/(Authorization\s*:\s*Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
                '$1[REDACTED]',
                $value
            );
            $value = preg_replace(
                '/([?&](?:token|api[_-]?key|access[_-]?code|secret|password)=)[^&\s]+/i',
                '$1[REDACTED]',
                $value
            );
        }

        return $value;
    }
}
