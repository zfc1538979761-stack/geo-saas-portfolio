<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class OrderResult
{
    private string $externalOrderNo;
    private string $status;
    private string $supplierRawStatus;
    private bool $supplierStatusKnown;
    private ?string $publishedUrl;
    private ?SupplierFailure $failure;
    private ?string $idempotencyKey;
    private ?SupplierRawPayload $rawPayload;

    public function __construct(
        string $externalOrderNo,
        string $status,
        string $supplierRawStatus,
        bool $supplierStatusKnown,
        ?string $publishedUrl,
        ?SupplierFailure $failure = null,
        ?string $idempotencyKey = null,
        ?SupplierRawPayload $rawPayload = null
    ) {
        $this->externalOrderNo = trim($externalOrderNo);
        $this->status = trim($status);
        $this->supplierRawStatus = trim($supplierRawStatus);
        $this->supplierStatusKnown = $supplierStatusKnown;
        $this->publishedUrl = $publishedUrl;
        $this->failure = $failure;
        $this->idempotencyKey = $idempotencyKey === null ? null : trim($idempotencyKey);
        $this->rawPayload = $rawPayload;
    }

    public function externalOrderNo(): string { return $this->externalOrderNo; }
    public function status(): string { return $this->status; }
    public function supplierRawStatus(): string { return $this->supplierRawStatus; }
    public function supplierStatusKnown(): bool { return $this->supplierStatusKnown; }
    public function publishedUrl(): ?string { return $this->publishedUrl; }
    public function failure(): ?SupplierFailure { return $this->failure; }
    public function idempotencyKey(): ?string { return $this->idempotencyKey; }
    public function rawPayload(): ?SupplierRawPayload { return $this->rawPayload; }

    public function toArray(): array
    {
        return [
            'externalOrderNo' => $this->externalOrderNo,
            'status' => $this->status,
            'supplierRawStatus' => $this->supplierRawStatus,
            'supplierStatusKnown' => $this->supplierStatusKnown,
            'publishedUrl' => $this->publishedUrl,
            'failure' => $this->failure === null ? null : $this->failure->toArray(),
            'idempotencyKey' => $this->idempotencyKey,
            'rawPayload' => $this->rawPayload === null ? null : $this->rawPayload->toArray(),
        ];
    }
}
