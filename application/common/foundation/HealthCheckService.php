<?php

declare(strict_types=1);

namespace app\common\foundation;

use Throwable;

final class HealthCheckService
{
    private $databaseProbe;
    private $redisProbe;

    public function __construct(callable $databaseProbe, callable $redisProbe)
    {
        $this->databaseProbe = $databaseProbe;
        $this->redisProbe = $redisProbe;
    }

    public function check(): array
    {
        $dependencies = [
            'database' => $this->runProbe($this->databaseProbe),
            'redis' => $this->runProbe($this->redisProbe),
        ];
        $healthy = !in_array('unavailable', $dependencies, true);

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'http_status' => $healthy ? 200 : 503,
            'dependencies' => $dependencies,
        ];
    }

    private function runProbe(callable $probe): string
    {
        try {
            $probe();
            return 'available';
        } catch (Throwable $exception) {
            return 'unavailable';
        }
    }
}
