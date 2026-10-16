<?php

declare(strict_types=1);

namespace app\common\foundation;

use DateTimeImmutable;
use DateTimeZone;

final class ApiResponse
{
    public static function success(
        $data = null,
        string $message = 'ok',
        ?string $requestId = null,
        ?DateTimeImmutable $now = null
    ): array {
        return self::envelope(0, $message, $data, $requestId, $now);
    }

    public static function error(
        int $code,
        string $message,
        $data = null,
        ?string $requestId = null,
        ?DateTimeImmutable $now = null
    ): array {
        return self::envelope($code, $message, $data, $requestId, $now);
    }

    public static function legacy(
        int $code,
        string $message,
        $data = null,
        ?string $requestId = null,
        ?DateTimeImmutable $now = null
    ): array {
        return self::envelope($code, $message, $data, $requestId, $now);
    }

    public static function requestIdFromServer(array $server): string
    {
        $candidate = isset($server['HTTP_X_REQUEST_ID'])
            ? trim((string) $server['HTTP_X_REQUEST_ID'])
            : '';

        return self::isValidRequestId($candidate) ? $candidate : bin2hex(random_bytes(16));
    }

    public static function isValidRequestId(string $requestId): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{8,128}$/', $requestId) === 1;
    }

    private static function envelope(
        int $code,
        string $message,
        $data,
        ?string $requestId,
        ?DateTimeImmutable $now
    ): array {
        $timestamp = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $resolvedRequestId = $requestId && self::isValidRequestId($requestId)
            ? $requestId
            : self::requestIdFromServer($_SERVER);

        return [
            'code' => $code,
            'msg' => $message,
            'time' => $timestamp->getTimestamp(),
            'data' => $data,
            'request_id' => $resolvedRequestId,
            'timestamp' => $timestamp->format(DATE_ATOM),
        ];
    }
}
