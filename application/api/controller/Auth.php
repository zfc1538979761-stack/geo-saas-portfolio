<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\foundation\ApiErrorCode;
use app\common\foundation\ApiResponse;
use app\common\foundation\SensitiveDataRedactor;
use app\common\identity\IdentityException;
use app\common\identity\IdentityServiceFactory;
use think\Log;
use think\Request;
use think\Response;
use Throwable;

final class Auth
{
    public function _empty(string $action): Response
    {
        if ($action === 'change-password') {
            return $this->changePassword();
        }

        $requestId = ApiResponse::requestIdFromServer($_SERVER);
        return $this->response(
            ApiResponse::error(ApiErrorCode::NOT_FOUND, 'Not found', null, $requestId),
            404,
            $requestId
        );
    }

    public function login(): Response
    {
        return $this->execute(function (Request $request, string $requestId): array {
            $this->requireMethod($request, 'POST');
            return IdentityServiceFactory::create()->login(
                (string) $request->post('account', ''),
                (string) $request->post('password', '', null),
                (string) $request->ip(0, false),
                $requestId
            );
        });
    }

    public function logout(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            IdentityServiceFactory::create()->logout($this->bearerToken($request));
            return [];
        });
    }

    public function current(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            return IdentityServiceFactory::create()->current($this->bearerToken($request));
        });
    }

    public function changePassword(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            IdentityServiceFactory::create()->changePassword(
                $this->bearerToken($request),
                (string) $request->post('current_password', '', null),
                (string) $request->post('new_password', '', null)
            );
            return [];
        });
    }

    private function execute(callable $operation): Response
    {
        $request = Request::instance();
        $requestId = ApiResponse::requestIdFromServer($_SERVER);
        try {
            $data = $operation($request, $requestId);
            return $this->response(ApiResponse::success($data, 'ok', $requestId), 200, $requestId);
        } catch (IdentityException $exception) {
            return $this->response(
                ApiResponse::error(
                    $exception->errorCode(),
                    $exception->getMessage(),
                    null,
                    $requestId
                ),
                $exception->httpStatus(),
                $requestId
            );
        } catch (Throwable $exception) {
            Log::record(
                json_encode(
                    SensitiveDataRedactor::redact([
                        'category' => 'security',
                        'event' => 'enterprise_auth_internal_error',
                        'request_id' => $requestId,
                        'exception_class' => str_replace('\\', '.', get_class($exception)),
                        'exception_code' => (string) $exception->getCode(),
                    ]),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'error'
            );
            return $this->response(
                ApiResponse::error(ApiErrorCode::INTERNAL_ERROR, '服务暂时不可用', null, $requestId),
                500,
                $requestId
            );
        }
    }

    private function requireMethod(Request $request, string $method): void
    {
        if (strtoupper($request->method()) !== $method) {
            throw new IdentityException(ApiErrorCode::INVALID_REQUEST, '请求方法不允许', 405);
        }
    }

    private function bearerToken(Request $request): string
    {
        $authorization = trim((string) $request->server('HTTP_AUTHORIZATION', ''));
        if (preg_match('/^Bearer\s+([A-Za-z0-9._~-]{20,200})$/i', $authorization, $matches) === 1) {
            return $matches[1];
        }
        $tokenHeader = trim((string) $request->server('HTTP_TOKEN', ''));
        if (preg_match('/^[A-Za-z0-9._~-]{20,200}$/', $tokenHeader) === 1) {
            return $tokenHeader;
        }

        throw new IdentityException(ApiErrorCode::UNAUTHENTICATED, '登录状态无效或已过期', 401);
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
