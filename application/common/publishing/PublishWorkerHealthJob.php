<?php

declare(strict_types=1);

namespace app\common\publishing;

use app\common\job\AbstractQueueJob;

final class PublishWorkerHealthJob extends AbstractQueueJob
{
    public static function markerPath(string $token): string
    {
        self::validateToken($token);
        return ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR . 'publish_worker_health'
            . DIRECTORY_SEPARATOR . $token . '.json';
    }

    public static function runInline(array $payload): void
    {
        $token = (string) ($payload['token'] ?? '');
        $path = self::markerPath($token);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Publish Worker health marker directory could not be created.');
        }
        $marker = json_encode([
            'token' => $token,
            'queue' => 'geo_publish_submit',
            'worker_pid' => getmypid(),
            'consumed_at' => date(DATE_ATOM),
        ], JSON_THROW_ON_ERROR);
        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $marker, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Publish Worker health marker could not be written.');
        }
    }

    protected function handle(array $payload): void
    {
        self::runInline($payload);
    }

    private static function validateToken(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Publish Worker health token is invalid.');
        }
    }
}
