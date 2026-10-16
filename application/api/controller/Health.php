<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\foundation\ApiResponse;
use app\common\foundation\HealthCheckService;
use Redis;
use think\Config;
use think\Db;
use think\Response;

final class Health
{
    public function index(): Response
    {
        $service = new HealthCheckService(
            static function (): void {
                Db::query('SELECT 1');
            },
            static function (): void {
                $queue = Config::get('queue');
                if (!is_array($queue)) {
                    $queue = require APP_PATH . 'extra' . DS . 'queue.php';
                }

                $redis = new Redis();
                $connected = $redis->connect(
                    (string) $queue['host'],
                    (int) $queue['port'],
                    (float) $queue['timeout']
                );
                if (!$connected) {
                    throw new \RuntimeException('Redis dependency unavailable.');
                }
                if ((string) $queue['password'] !== '' && !$redis->auth((string) $queue['password'])) {
                    throw new \RuntimeException('Redis dependency unavailable.');
                }
                if (!$redis->select((int) $queue['select']) || !$redis->ping()) {
                    throw new \RuntimeException('Redis dependency unavailable.');
                }
                $redis->close();
            }
        );

        $report = $service->check();
        $httpStatus = $report['http_status'];
        $status = $report['status'] === 'ok' ? 'normal' : 'abnormal';
        $requestId = ApiResponse::requestIdFromServer($_SERVER);
        $payload = ApiResponse::success([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $status, $requestId);

        return Response::create($payload, 'json', $httpStatus)
            ->header(['X-Request-Id' => $requestId, 'Cache-Control' => 'no-store']);
    }
}
