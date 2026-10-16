<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class Channel
{
    private string $code;
    private string $name;
    private string $category;
    private string $status;
    private bool $supplierConfirmed;

    public function __construct(
        string $code,
        string $name,
        string $category,
        string $status,
        bool $supplierConfirmed
    ) {
        $code = trim($code);
        $name = trim($name);
        $category = trim($category);
        $status = trim($status);
        if ($code === '' || $name === '' || $category === '' || $status === '') {
            throw new InvalidArgumentException('Channel fields must not be empty.');
        }
        $this->code = $code;
        $this->name = $name;
        $this->category = $category;
        $this->status = $status;
        $this->supplierConfirmed = $supplierConfirmed;
    }

    public function code(): string { return $this->code; }
    public function name(): string { return $this->name; }
    public function category(): string { return $this->category; }
    public function status(): string { return $this->status; }
    public function supplierConfirmed(): bool { return $this->supplierConfirmed; }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'status' => $this->status,
            'supplierConfirmed' => $this->supplierConfirmed,
        ];
    }
}
