<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class OrderSubmitItemResult
{
    private string $externalMediaId;
    private string $status;
    private ?SupplierFailure $failure;

    public function __construct(string $externalMediaId, string $status, ?SupplierFailure $failure = null)
    {
        $externalMediaId = trim($externalMediaId);
        if ($externalMediaId === '') {
            throw new InvalidArgumentException('Order item result requires an external media id.');
        }
        $this->externalMediaId = $externalMediaId;
        $this->status = trim($status);
        $this->failure = $failure;
    }

    public function externalMediaId(): string { return $this->externalMediaId; }
    public function status(): string { return $this->status; }
    public function failure(): ?SupplierFailure { return $this->failure; }

    public function toArray(): array
    {
        return [
            'externalMediaId' => $this->externalMediaId,
            'status' => $this->status,
            'failure' => $this->failure === null ? null : $this->failure->toArray(),
        ];
    }
}
