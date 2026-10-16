<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\ai\exception\AiProviderException;
use app\common\content\ContentException;
use app\common\foundation\ApiErrorCode;
use app\common\geo\GeoException;
use app\common\geo\visibility\VisibilityProviderException;

abstract class GeoApiController extends T06ApiController
{
    protected function geo(callable $operation): array
    {
        try {
            return $operation();
        } catch (GeoException $exception) {
            $status = $exception->httpStatus();
            $code = ApiErrorCode::INVALID_REQUEST;
            if ($status === 403) { $code = ApiErrorCode::FORBIDDEN; }
            elseif ($status === 404) { $code = ApiErrorCode::NOT_FOUND; }
            elseif ($status >= 502) { $code = ApiErrorCode::DEPENDENCY_UNAVAILABLE; }
            elseif ($status >= 500) { $code = ApiErrorCode::INTERNAL_ERROR; }
            throw new ContentException($code, $exception->getMessage(), $status);
        } catch (VisibilityProviderException $exception) {
            throw new ContentException(
                ApiErrorCode::DEPENDENCY_UNAVAILABLE,
                $exception->getMessage(),
                503,
                [
                    'error_category' => $exception->category(),
                    'provider_code' => $exception->providerCode(),
                    'external_code' => $exception->externalCode(),
                    'retryable' => $exception->retryable(),
                    'network_executed' => $exception->networkExecuted(),
                ]
            );
        } catch (AiProviderException $exception) {
            throw new ContentException(
                ApiErrorCode::DEPENDENCY_UNAVAILABLE,
                $exception->getMessage(),
                503,
                [
                    'error_category' => $exception->category(),
                    'retryable' => $exception->retryable(),
                    'provider_request_id' => $exception->requestId() === '' ? null : $exception->requestId(),
                ]
            );
        }
    }

    protected function page($value, int $default = 1): int
    {
        if ($value === null || $value === '') { return $default; }
        return $this->integer($value);
    }

    protected function perPage($value, int $default = 20): int
    {
        $perPage = $this->page($value, $default);
        if ($perPage > 100) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'per_page cannot exceed 100.', 422);
        }
        return $perPage;
    }
}
