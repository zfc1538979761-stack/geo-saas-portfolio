<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\publishing\PublishException;
use app\common\publishing\PublishSubmitDispatcher;

$pass = 0;
$fail = 0;

function dispatchTest(string $name, callable $test): void
{
    global $pass, $fail;
    try {
        $test();
        echo "PASS: {$name}\n";
        $pass++;
    } catch (Throwable $exception) {
        echo "FAIL: {$name} - {$exception->getMessage()}\n";
        $fail++;
    }
}

function dispatchAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

dispatchTest('disabled auto-submit rejects before local order creation', function (): void {
    $enqueueCalls = 0;
    $dispatcher = new PublishSubmitDispatcher(false, function () use (&$enqueueCalls) {
        $enqueueCalls++;
        return 'unexpected-job';
    });

    $threw = false;
    try {
        $dispatcher->assertReady();
    } catch (PublishException $exception) {
        $threw = $exception->getCode() === 1544;
    }

    dispatchAssert($threw, 'disabled submission must fail with the safe readiness error');
    dispatchAssert($enqueueCalls === 0, 'readiness failure must not enqueue supplier work');

    $controller = (string) file_get_contents(ROOT_PATH . 'application/api/controller/Order.php');
    $readinessPosition = strpos($controller, '$dispatcher->assertReady();');
    $createPosition = strpos($controller, '$service->create(');
    dispatchAssert($readinessPosition !== false, 'production controller must perform the readiness preflight');
    dispatchAssert($createPosition !== false && $readinessPosition < $createPosition, 'readiness preflight must happen before local order creation and wallet freeze');
});

dispatchTest('enabled auto-submit still rejects when Worker is not ready', function (): void {
    $enqueueCalls = 0;
    $dispatcher = new PublishSubmitDispatcher(
        true,
        function () use (&$enqueueCalls) {
            $enqueueCalls++;
            return 'unexpected-job';
        },
        static fn(): bool => false
    );

    $threw = false;
    try {
        $dispatcher->assertReady();
    } catch (PublishException $exception) {
        $threw = $exception->getCode() === 1547;
    }

    dispatchAssert($threw, 'unready Worker must fail before local order creation');
    dispatchAssert($enqueueCalls === 0, 'unready Worker must not enqueue supplier work');
});

dispatchTest('successful Redis push is the only queued outcome', function (): void {
    $queued = 0;
    $failed = 0;
    $dispatcher = new PublishSubmitDispatcher(true, static function (array $payload, string $queue): string {
        dispatchAssert($payload === ['company_id' => 1019, 'order_id' => 845], 'queue payload must identify one existing order');
        dispatchAssert($queue === 'geo_publish_submit', 'publisher must use the dedicated queue');
        return 'redis-job-845';
    });

    $result = $dispatcher->dispatch(
        1019,
        845,
        function () use (&$queued): void { $queued++; },
        function () use (&$failed): void { $failed++; }
    );

    dispatchAssert($result['state'] === 'queued', 'successful push must report queued');
    dispatchAssert($result['job_id'] === 'redis-job-845', 'successful push must retain the queue job id');
    dispatchAssert($queued === 1, 'local order must enter queued once');
    dispatchAssert($failed === 0, 'successful push must not mark queue failure');
});

dispatchTest('failed Redis push cannot be reported as queued', function (): void {
    $queued = 0;
    $failed = 0;
    $dispatcher = new PublishSubmitDispatcher(true, static fn() => false);

    $result = $dispatcher->dispatch(
        1019,
        845,
        function () use (&$queued): void { $queued++; },
        function () use (&$failed): void { $failed++; }
    );

    dispatchAssert($result['state'] === 'queue_failed', 'failed push must be an explicit recoverable failure');
    dispatchAssert($result['job_id'] === null, 'failed push must not invent a queue job id');
    dispatchAssert($queued === 1, 'queue transition occurs before the Redis boundary');
    dispatchAssert($failed === 1, 'failed push must move the order to recovery exactly once');
});

echo "\nPublish dispatch: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
