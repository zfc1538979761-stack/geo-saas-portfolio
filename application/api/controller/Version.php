<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\foundation\ApiResponse;
use think\Config;
use think\Response;

final class Version
{
    public function index(): Response
    {
        $geo = (array) Config::get('geo');
        $data = [
            'name' => (string) ($geo['name'] ?? 'GEO优化SaaS系统'),
            'version' => (string) ($geo['version'] ?? 'unknown'),
            'release' => (string) ($geo['release'] ?? 'unknown'),
            'environment' => (string) ($geo['environment'] ?? 'unknown'),
            'schema' => (string) ($geo['schema'] ?? 'unknown'),
        ];
        $requestId = ApiResponse::requestIdFromServer($_SERVER);

        return Response::create(ApiResponse::success($data, 'ok', $requestId), 'json', 200)
            ->header(['X-Request-Id' => $requestId, 'Cache-Control' => 'no-store']);
    }
}
