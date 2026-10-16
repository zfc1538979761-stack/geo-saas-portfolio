<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class OrderSubmitResult
{
    private string $idempotencyKey;
    private ?string $externalOrderNo;
    private string $status;
    private ?string $supplierRawStatus;
    private ?string $publishedUrl;
    private ?SupplierFailure $failure;
    private array $itemResults;
    private ?string $supplierRequestId;

    public function __construct(
        string $idempotencyKey,
        ?string $externalOrderNo,
        string $status,
        ?string $supplierRawStatus,
        ?string $publishedUrl,
        ?SupplierFailure $failure,
        array $itemResults,
        ?string $supplierRequestId = null
    ) {
        foreach ($itemResults as $itemResult) {
            if (!$itemResult instanceof OrderSubmitItemResult) {
                throw new InvalidArgumentException('Order submit item results must be OrderSubmitItemResult instances.');
            }
        }
        $this->idempotencyKey = trim($idempotencyKey);
        $this->externalOrderNo = $externalOrderNo === null ? null : trim($externalOrderNo);
        $this->status = trim($status);
        $this->supplierRawStatus = $supplierRawStatus === null ? null : trim($supplierRawStatus);
        $this->publishedUrl = $publishedUrl;
        $this->failure = $failure;
        $this->itemResults = array_values($itemResults);
        $this->supplierRequestId = $supplierRequestId === null ? null : trim($supplierRequestId);
    }

    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function externalOrderNo(): ?string { return $this->externalOrderNo; }
    public function status(): string { return $this->status; }
    public function supplierRawStatus(): ?string { return $this->supplierRawStatus; }
    public function publishedUrl(): ?string { return $this->publishedUrl; }
    public function failure(): ?SupplierFailure { return $this->failure; }
    public function itemResults(): array { return $this->itemResults; }
    public function supplierRequestId(): ?string { return $this->supplierRequestId; }
    public function failedItemResults(): array
    {
        return array_values(array_filter(
            $this->itemResults,
            static fn(OrderSubmitItemResult $item): bool => $item->failure() !== null
        ));
    }
    public function successfulItemIds(): array
    {
        return array_values(array_map(
            static fn(OrderSubmitItemResult $item): string => $item->externalMediaId(),
            array_filter(
                $this->itemResults,
                static fn(OrderSubmitItemResult $item): bool => $item->failure() === null
            )
        ));
    }
    public function failedItemIds(): array
    {
        return array_values(array_map(
            static fn(OrderSubmitItemResult $item): string => $item->externalMediaId(),
            $this->failedItemResults()
        ));
    }
    public function isPartial(): bool
    {
        $failed = $this->failedItemResults();
        return $failed !== [] && count($failed) < count($this->itemResults);
    }

    public function toArray(): array
    {
        return [
            'idempotencyKey' => $this->idempotencyKey,
            'externalOrderNo' => $this->externalOrderNo,
            'status' => $this->status,
            'supplierRawStatus' => $this->supplierRawStatus,
            'publishedUrl' => $this->publishedUrl,
            'failure' => $this->failure === null ? null : $this->failure->toArray(),
            'itemResults' => array_map(
                static fn(OrderSubmitItemResult $item): array => $item->toArray(),
                $this->itemResults
            ),
            'supplierRequestId' => $this->supplierRequestId,
        ];
    }
}
