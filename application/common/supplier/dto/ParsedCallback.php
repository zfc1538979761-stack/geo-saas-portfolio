<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class ParsedCallback
{
    private string $eventId;
    private string $eventType;
    private string $externalOrderNo;
    private string $status;
    private string $supplierRawStatus;
    private ?string $publishedUrl;
    private ?SupplierFailure $failure;
    private bool $duplicate;
    private SupplierRawPayload $rawPayload;

    public function __construct(
        string $eventId,
        string $eventType,
        string $externalOrderNo,
        string $status,
        string $supplierRawStatus,
        ?string $publishedUrl,
        ?SupplierFailure $failure,
        bool $duplicate,
        SupplierRawPayload $rawPayload
    ) {
        $this->eventId = trim($eventId);
        $this->eventType = trim($eventType);
        $this->externalOrderNo = trim($externalOrderNo);
        $this->status = trim($status);
        $this->supplierRawStatus = trim($supplierRawStatus);
        $this->publishedUrl = $publishedUrl;
        $this->failure = $failure;
        $this->duplicate = $duplicate;
        $this->rawPayload = $rawPayload;
    }

    public function eventId(): string { return $this->eventId; }
    public function eventType(): string { return $this->eventType; }
    public function externalOrderNo(): string { return $this->externalOrderNo; }
    public function status(): string { return $this->status; }
    public function supplierRawStatus(): string { return $this->supplierRawStatus; }
    public function publishedUrl(): ?string { return $this->publishedUrl; }
    public function failure(): ?SupplierFailure { return $this->failure; }
    public function isDuplicate(): bool { return $this->duplicate; }
    public function rawPayload(): SupplierRawPayload { return $this->rawPayload; }

    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'eventType' => $this->eventType,
            'externalOrderNo' => $this->externalOrderNo,
            'status' => $this->status,
            'supplierRawStatus' => $this->supplierRawStatus,
            'publishedUrl' => $this->publishedUrl,
            'failure' => $this->failure === null ? null : $this->failure->toArray(),
            'duplicate' => $this->duplicate,
            'rawPayload' => $this->rawPayload->toArray(),
        ];
    }
}
