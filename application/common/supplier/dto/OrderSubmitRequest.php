<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class OrderSubmitRequest
{
    private string $idempotencyKey;
    private string $channelCode;
    private array $externalMediaIds;
    private array $context;

    public function __construct(string $idempotencyKey, string $channelCode, array $externalMediaIds, array $context = [])
    {
        $idempotencyKey = trim($idempotencyKey);
        $channelCode = trim($channelCode);
        if (
            preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $idempotencyKey) !== 1
            || $channelCode === ''
            || $externalMediaIds === []
            || count($externalMediaIds) > 100
        ) {
            throw new InvalidArgumentException('Order submit request is invalid.');
        }
        $ids = [];
        foreach ($externalMediaIds as $externalMediaId) {
            $externalMediaId = trim((string) $externalMediaId);
            if ($externalMediaId === '') {
                throw new InvalidArgumentException('Order submit media ids must not be empty.');
            }
            $ids[] = $externalMediaId;
        }
        $this->idempotencyKey = $idempotencyKey;
        $this->channelCode = $channelCode;
        $this->externalMediaIds = array_values(array_unique($ids));
        $this->context = $context;
    }

    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function channelCode(): string { return $this->channelCode; }
    public function externalMediaIds(): array { return $this->externalMediaIds; }
    public function context(): array { return $this->context; }

    public function toArray(): array
    {
        return [
            'idempotencyKey' => $this->idempotencyKey,
            'channelCode' => $this->channelCode,
            'externalMediaIds' => $this->externalMediaIds,
        ];
    }
}
