<?php

declare(strict_types=1);

namespace app\common\foundation;

use InvalidArgumentException;

final class RuntimeEnvironment
{
    private const REQUIRED_KEYS = [
        'app.token_key',
        'company.contact_encryption_key',
        'database.hostname',
        'database.database',
        'database.username',
        'database.password',
        'database.hostport',
    ];

    private $name;
    private $allowsDebug;
    private $allowsTrace;

    private function __construct(string $name, bool $allowsDebug, bool $allowsTrace)
    {
        $this->name = $name;
        $this->allowsDebug = $allowsDebug;
        $this->allowsTrace = $allowsTrace;
    }

    public static function fromName(string $name): self
    {
        $normalized = strtolower(trim($name));
        $profiles = [
            'development' => [true, true],
            'testing' => [true, false],
            'production' => [false, false],
        ];

        if (!isset($profiles[$normalized])) {
            throw new InvalidArgumentException(
                'Unsupported app.env. Allowed values: development, testing, production.'
            );
        }

        return new self($normalized, $profiles[$normalized][0], $profiles[$normalized][1]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function allowsDebug(): bool
    {
        return $this->allowsDebug;
    }

    public function allowsTrace(): bool
    {
        return $this->allowsTrace;
    }

    public function missingRequiredKeys(array $configuration): array
    {
        $missing = [];
        foreach (self::REQUIRED_KEYS as $path) {
            [$section, $key] = explode('.', $path, 2);
            $value = $configuration[$section][$key] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                $missing[] = $path;
            }
        }

        return $missing;
    }

    public static function isValidContactEncryptionKey(string $value): bool
    {
        if ($value !== trim($value) || strpos($value, 'base64:') !== 0) {
            return false;
        }
        $decoded = base64_decode(substr($value, 7), true);
        return is_string($decoded) && strlen($decoded) === 32;
    }

    public static function resolveContactEncryptionKey($processValue, $iniValue): string
    {
        return (string) ($processValue !== false ? $processValue : $iniValue);
    }
}
