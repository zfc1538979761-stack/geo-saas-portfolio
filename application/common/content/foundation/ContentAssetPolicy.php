<?php

declare(strict_types=1);

namespace app\common\content\foundation;

use InvalidArgumentException;

final class ContentAssetPolicy
{
    private $types;
    private $extensions;
    private $maximumOriginalNameLength;

    public function __construct(array $config)
    {
        $types = array_values(array_map('strval', (array) ($config['knowledge_types'] ?? [])));
        $extensions = (array) ($config['extensions'] ?? []);
        if ($types !== ['text', 'image', 'document'] || $extensions === []) {
            throw new InvalidArgumentException('T06 file policy is incomplete.');
        }
        $normalized = [];
        foreach ($extensions as $extension => $definition) {
            $extension = strtolower((string) $extension);
            $definition = (array) $definition;
            $type = (string) ($definition['type'] ?? '');
            $mime = (string) ($definition['mime'] ?? '');
            $maximum = (int) ($definition['max_bytes'] ?? 0);
            if (
                preg_match('/^[a-z0-9]+$/D', $extension) !== 1
                || !in_array($type, ['image', 'document'], true)
                || preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#Di', $mime) !== 1
                || $maximum < 1
            ) {
                throw new InvalidArgumentException('T06 file policy contains an invalid rule.');
            }
            $normalized[$extension] = ['type' => $type, 'mime' => strtolower($mime), 'max_bytes' => $maximum];
        }
        $maximumName = (int) ($config['maximum_original_name_length'] ?? 0);
        if ($maximumName < 16 || $maximumName > 255) {
            throw new InvalidArgumentException('T06 filename policy is invalid.');
        }
        $this->types = $types;
        $this->extensions = $normalized;
        $this->maximumOriginalNameLength = $maximumName;
    }

    public function knowledgeTypes(): array
    {
        return $this->types;
    }

    public function hasExtension(string $extension): bool
    {
        return isset($this->extensions[strtolower($extension)]);
    }

    public function typeFor(string $extension): string
    {
        return (string) $this->definition($extension)['type'];
    }

    public function mimeFor(string $extension): string
    {
        return (string) $this->definition($extension)['mime'];
    }

    public function maximumBytes(string $extension): int
    {
        return (int) $this->definition($extension)['max_bytes'];
    }

    public function maximumOriginalNameLength(): int
    {
        return $this->maximumOriginalNameLength;
    }

    private function definition(string $extension): array
    {
        $extension = strtolower($extension);
        if (!isset($this->extensions[$extension])) {
            throw new InvalidArgumentException('File extension is not allowed.');
        }
        return $this->extensions[$extension];
    }
}
