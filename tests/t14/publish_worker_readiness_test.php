<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\publishing\PublishWorkerReadiness;

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'geo-publish-readiness-' . bin2hex(random_bytes(8)) . '.json';
$lockPath = substr($temp, 0, -5) . '.lock';
$lockHandle = null;

function readinessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    readinessAssert(!PublishWorkerReadiness::isReady($temp, 120, 1_000), 'missing heartbeat must fail closed');

    file_put_contents($temp, json_encode([
        'queue' => 'geo_publish_submit',
        'state' => 'ready',
        'supervisor_pid' => 123,
        'updated_at_epoch' => 800,
    ], JSON_THROW_ON_ERROR));
    readinessAssert(!PublishWorkerReadiness::isReady($temp, 120, 1_000), 'stale heartbeat must fail closed');

    file_put_contents($temp, json_encode([
        'queue' => 'geo_ai_generation',
        'state' => 'ready',
        'supervisor_pid' => 123,
        'updated_at_epoch' => 990,
    ], JSON_THROW_ON_ERROR));
    readinessAssert(!PublishWorkerReadiness::isReady($temp, 120, 1_000), 'wrong queue heartbeat must fail closed');

    file_put_contents($temp, json_encode([
        'queue' => 'geo_publish_submit',
        'state' => 'ready',
        'supervisor_pid' => 123,
        'updated_at_epoch' => 990,
    ], JSON_THROW_ON_ERROR));
    readinessAssert(!PublishWorkerReadiness::isReady($temp, 120, 1_000), 'fresh heartbeat without a live supervisor lock must fail closed');

    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('test could not acquire the simulated supervisor lock');
    }
    readinessAssert(PublishWorkerReadiness::isReady($temp, 120, 1_000), 'fresh publish heartbeat must be ready');

    $controller = (string) file_get_contents(ROOT_PATH . 'application/api/controller/Order.php');
    readinessAssert(strpos($controller, 'PublishWorkerReadiness::isReady') !== false, 'order preflight must include actual Worker readiness');

    echo "PASS: publish Worker readiness fails closed for missing, stale, and wrong heartbeats\n";
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    if (is_file($temp)) {
        unlink($temp);
    }
    if (is_file($lockPath)) {
        unlink($lockPath);
    }
}
