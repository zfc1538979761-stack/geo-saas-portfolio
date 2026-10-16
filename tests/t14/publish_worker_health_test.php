<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\publishing\PublishWorkerHealthJob;

$token = bin2hex(random_bytes(16));
$path = PublishWorkerHealthJob::markerPath($token);

try {
    PublishWorkerHealthJob::runInline(['token' => $token]);
    if (!is_file($path)) {
        throw new RuntimeException('health job did not produce its local marker');
    }
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($payload['token'] ?? '') !== $token || ($payload['queue'] ?? '') !== 'geo_publish_submit') {
        throw new RuntimeException('health marker is invalid');
    }
    $commands = require APP_PATH . 'command.php';
    if (!in_array('app\\admin\\command\\GeoPublishWorkerHealth', $commands, true)) {
        throw new RuntimeException('health command is not registered');
    }
    echo "PASS: publish Worker health job records consumption without supplier code\n";
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}
