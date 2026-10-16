<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\content\ContentException;
use app\common\content\ContentAuthenticationException;
use app\common\company\intelligence\CompanyDiscoveryException;
use app\common\foundation\ApiErrorCode;
use app\common\foundation\ApiResponse;
use app\common\foundation\SensitiveDataRedactor;
use think\Log;
use think\Request;
use think\Response;
use Throwable;

abstract class ContentApiController
{
    protected function execute(callable $operation): Response
    {
        $request = Request::instance();
        $requestId = ApiResponse::requestIdFromServer($_SERVER);
        try {
            $data = $operation($request);
            return $this->response(ApiResponse::success($data, 'ok', $requestId), 200, $requestId);
        } catch (ContentException $exception) {
            return $this->response(
                ApiResponse::error(
                    $exception->errorCode(),
                    $exception->getMessage(),
                    $exception->details(),
                    $requestId
                ),
                $exception->httpStatus(),
                $requestId
            );
        } catch (ContentAuthenticationException $exception) {
            return $this->response(
                ApiResponse::error(ApiErrorCode::UNAUTHENTICATED, 'Login state is invalid or expired.', null, $requestId),
                401,
                $requestId
            );
        } catch (CompanyDiscoveryException $exception) {
            $code = ApiErrorCode::INVALID_REQUEST;
            if ($exception->httpStatus() === 404) { $code = ApiErrorCode::NOT_FOUND; }
            if ($exception->httpStatus() >= 500) { $code = ApiErrorCode::DEPENDENCY_UNAVAILABLE; }
            return $this->response(
                ApiResponse::error($code, $exception->getMessage(), [
                    'error_category' => $exception->category(),
                    'provider_code' => $exception->providerCode(),
                    'external_code' => $exception->externalCode(),
                    'retryable' => $exception->retryable(),
                    'network_executed' => $exception->networkExecuted(),
                    'context' => $exception->details(),
                ], $requestId),
                $exception->httpStatus(),
                $requestId
            );
        } catch (Throwable $exception) {
            Log::record(json_encode(SensitiveDataRedactor::redact([
                'category' => 'security',
                'event' => 'keyword_question_internal_error',
                'request_id' => $requestId,
                'exception_class' => str_replace('\\', '.', get_class($exception)),
                'exception_code' => (string) $exception->getCode(),
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'error');
            return $this->response(
                ApiResponse::error(ApiErrorCode::INTERNAL_ERROR, 'Service temporarily unavailable.', null, $requestId),
                500,
                $requestId
            );
        }
    }

    protected function requireMethod(Request $request, string $method): void
    {
        if (strtoupper($request->method()) !== $method) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Request method is not allowed.', 405);
        }
    }

    protected function bearerToken(Request $request): string
    {
        $authorization = trim((string) $request->server('HTTP_AUTHORIZATION', ''));
        if (preg_match('/^Bearer\s+([A-Za-z0-9._~-]{20,200})$/i', $authorization, $matches) === 1) {
            return $matches[1];
        }
        $token = trim((string) $request->server('HTTP_TOKEN', ''));
        if (preg_match('/^[A-Za-z0-9._~-]{20,200}$/', $token) === 1) {
            return $token;
        }
        throw new ContentAuthenticationException('Unauthenticated tenant context.');
    }

    protected function integer($value): int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value < 1) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Resource ID is invalid.', 422);
        }
        return (int) $value;
    }

    protected function strictInput(array $source, array $allowed): array
    {
        if (array_diff(array_keys($source), $allowed) !== []) {
            throw new ContentException(
                ApiErrorCode::INVALID_REQUEST,
                'Request contains protected or unknown fields.',
                422
            );
        }
        return $source;
    }

    private function response(array $payload, int $status, string $requestId): Response
    {
        return Response::create($payload, 'json', $status)->header([
            'X-Request-Id' => $requestId,
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
