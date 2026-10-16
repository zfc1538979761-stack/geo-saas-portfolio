<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class MediaAttributes
{
    private array $attributes;

    public function __construct(array $attributes)
    {
        $this->attributes = [];
        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            if ($key === '' || !is_scalar($value)) {
                throw new \InvalidArgumentException('Media attributes must use non-empty scalar values.');
            }
            $this->attributes[$key] = $value;
        }
    }

    public function all(): array
    {
        return $this->attributes;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}
