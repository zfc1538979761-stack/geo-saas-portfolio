<?php

namespace app\api\library;

use app\common\foundation\ApiErrorCode;
use app\common\foundation\ApiResponse;
use Exception;
use think\exception\Handle;
use think\Response;

/**
 * 自定义API模块的错误显示
 */
class ExceptionHandle extends Handle
{

    public function render(Exception $e)
    {
        // 在生产环境下返回code信息
        if (!\think\Config::get('app_debug')) {
            $statuscode = 500;
            $code = ApiErrorCode::INTERNAL_ERROR;
            $msg = 'An error occurred';
            // 验证异常
            if ($e instanceof \think\exception\ValidateException) {
                $code = ApiErrorCode::INVALID_REQUEST;
                $statuscode = 422;
                $msg = $e->getError();
            }
            // Http异常
            if ($e instanceof \think\exception\HttpException) {
                $statuscode = $e->getStatusCode();
                $code = $this->apiCodeForHttpStatus($statuscode);
                $msg = $statuscode === 404 ? 'Not found' : 'Request failed';
            }
            $requestId = ApiResponse::requestIdFromServer($_SERVER);
            return Response::create(
                ApiResponse::error($code, $msg, null, $requestId),
                'json',
                $statuscode
            )->header(['X-Request-Id' => $requestId, 'Cache-Control' => 'no-store']);
        }

        //其它此交由系统处理
        return parent::render($e);
    }

    private function apiCodeForHttpStatus(int $status): int
    {
        $codes = [
            401 => ApiErrorCode::UNAUTHENTICATED,
            403 => ApiErrorCode::FORBIDDEN,
            404 => ApiErrorCode::NOT_FOUND,
            503 => ApiErrorCode::DEPENDENCY_UNAVAILABLE,
        ];

        return $codes[$status] ?? ApiErrorCode::INTERNAL_ERROR;
    }

}
