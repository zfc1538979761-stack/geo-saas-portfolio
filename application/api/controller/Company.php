<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\company\CompanyException;
use app\common\company\CompanyProfileServiceFactory;
use app\common\foundation\ApiErrorCode;
use app\common\foundation\ApiResponse;
use app\common\foundation\SensitiveDataRedactor;
use app\common\tenant\TenantContextFactory;
use app\common\tenant\TenantException;
use think\Log;
use think\Request;
use think\Response;
use Throwable;

final class Company
{
    private const PROFILE_FIELDS = [
        'company_name',
        'brand',
        'industry',
        'products_services',
        'website',
        'introduction',
        'mobile',
        'email',
    ];

    public function profile(): Response
    {
        return $this->execute(function (Request $request): array {
            $method = strtoupper($request->method());
            if (!in_array($method, ['GET', 'POST'], true)) {
                throw new CompanyException(ApiErrorCode::INVALID_REQUEST, '请求方法不允许', 405);
            }
            $context = TenantContextFactory::fromEnterpriseToken($this->bearerToken($request));
            $service = CompanyProfileServiceFactory::create();
            if ($method === 'GET') {
                return $service->get($context);
            }

            $posted = (array) $request->post();
            $input = [];
            foreach (self::PROFILE_FIELDS as $field) {
                if (array_key_exists($field, $posted)) {
                    $input[$field] = $posted[$field];
                }
            }
            if (array_key_exists('company_id', $posted)) {
                $input['company_id'] = $posted['company_id'];
            }
            return $service->save($input, $context, (string) $request->ip(0, false));
        });
    }

    private function execute(callable $operation): Response
    {
        $request = Request::instance();
        $requestId = ApiResponse::requestIdFromServer($_SERVER);
        try {
            $data = $operation($request);
            return $this->response(ApiResponse::success($data, 'ok', $requestId), 200, $requestId);
        } catch (CompanyException $exception) {
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
        } catch (TenantException $exception) {
            return $this->response(
                ApiResponse::error(
                    ApiErrorCode::UNAUTHENTICATED,
                    '登录状态无效或已过期',
                    null,
                    $requestId
                ),
                401,
                $requestId
            );
        } catch (Throwable $exception) {
            Log::record(
                json_encode(
                    SensitiveDataRedactor::redact([
                        'category' => 'security',
                        'event' => 'company_profile_internal_error',
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
        throw new TenantException('Unauthenticated tenant context.');
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
