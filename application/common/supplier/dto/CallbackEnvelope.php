<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class CallbackEnvelope
{
    private string $supplierCode;
    private string $eventId;
    private SupplierRawPayload $rawPayload;

    public function __construct(string $supplierCode, string $eventId, SupplierRawPayload $rawPayload)
    {
        $supplierCode = trim($supplierCode);
        $eventId = trim($eventId);
        if ($supplierCode === '' || $eventId === '') {
            throw new InvalidArgumentException('Callback envelope requires a supplier code and event id.');
        }
        $this->supplierCode = $supplierCode;
        $this->eventId = $eventId;
        $this->rawPayload = $rawPayload;
    }

    public function supplierCode(): string { return $this->supplierCode; }
    public function eventId(): string { return $this->eventId; }
    public function rawPayload(): SupplierRawPayload { return $this->rawPayload; }

    public function toArray(): array
    {
        return [
            'supplierCode' => $this->supplierCode,
            'eventId' => $this->eventId,
            'rawPayload' => $this->rawPayload->toArray(),
        ];
    }
}
