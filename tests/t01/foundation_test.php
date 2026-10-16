<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);

require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\foundation\ApiResponse;
use app\common\foundation\HealthCheckService;
use app\common\foundation\RuntimeEnvironment;
use app\common\foundation\SensitiveDataRedactor;
use app\common\job\AbstractQueueJob;
use app\common\job\QueuePolicy;
use app\common\job\RetryableJobException;
use think\queue\Job;

final class FoundationTestFailure extends RuntimeException
{
}

function failTest(string $message): void
{
    throw new FoundationTestFailure($message);
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        failTest($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        failTest($message);
    }
}

function requireClass(string $class): void
{
    if (!class_exists($class)) {
        failTest("required class is missing: {$class}");
    }
}

$tests = [];

$tests['environment profiles fail closed'] = static function (): void {
    requireClass(RuntimeEnvironment::class);

    $development = RuntimeEnvironment::fromName('development');
    $production = RuntimeEnvironment::fromName('production');

    assertSameValue('development', $development->name(), 'development profile name');
    assertTrueValue($development->allowsDebug(), 'development may enable debug explicitly');
    assertSameValue('production', $production->name(), 'production profile name');
    assertTrueValue(!$production->allowsDebug(), 'production must not allow debug');
    assertTrueValue(!$production->allowsTrace(), 'production must not allow trace');

    $thrown = false;
    try {
        RuntimeEnvironment::fromName('preview');
    } catch (InvalidArgumentException $exception) {
        $thrown = true;
    }
    assertTrueValue($thrown, 'unknown environment must be rejected');

    $missing = $development->missingRequiredKeys([
        'app' => ['token_key' => 'configured'],
        'company' => ['contact_encryption_key' => 'configured'],
        'database' => [
            'hostname' => '127.0.0.1',
            'database' => '',
            'username' => '',
            'password' => '',
            'hostport' => '3306',
        ],
        'redis' => [],
    ]);
    sort($missing);
    assertSameValue(
        ['database.database', 'database.password', 'database.username'],
        $missing,
        'missing keys must be names only'
    );

    $validContactKey = 'base64:' . base64_encode(random_bytes(32));
    assertTrueValue(
        RuntimeEnvironment::isValidContactEncryptionKey($validContactKey),
        'a base64-encoded 32-byte contact key is valid'
    );
    foreach (['', 'change-me', 'base64:not-base64', 'base64:' . base64_encode(random_bytes(16))] as $badKey) {
        assertTrueValue(
            !RuntimeEnvironment::isValidContactEncryptionKey($badKey),
            'missing, malformed, and short contact keys must fail preflight'
        );
    }
    assertSameValue(
        '',
        RuntimeEnvironment::resolveContactEncryptionKey('', $validContactKey),
        'a defined empty process override must not fall back to INI'
    );
    assertSameValue(
        $validContactKey,
        RuntimeEnvironment::resolveContactEncryptionKey(false, $validContactKey),
        'an absent process override must use the INI key'
    );
};

$tests['api envelope is deterministic and safe'] = static function (): void {
    requireClass(ApiResponse::class);

    $payload = ApiResponse::success(
        ['ready' => true],
        'ready',
        'request-1234',
        new DateTimeImmutable('2026-07-30T15:00:00+08:00')
    );

    assertSameValue(0, $payload['code'], 'success code');
    assertSameValue('ready', $payload['msg'], 'success message');
    assertSameValue(['ready' => true], $payload['data'], 'success data');
    assertSameValue('request-1234', $payload['request_id'], 'request id');
    assertSameValue('2026-07-30T15:00:00+08:00', $payload['timestamp'], 'ISO timestamp');
    assertTrueValue(ApiResponse::isValidRequestId('request-1234'), 'valid request id');
    assertTrueValue(!ApiResponse::isValidRequestId('bad id'), 'spaces are rejected in request id');
};

$tests['sensitive data is recursively redacted'] = static function (): void {
    requireClass(SensitiveDataRedactor::class);

    $input = [
        'username' => 'account-reference',
        'password' => 'secret-value',
        'nested' => [
            'api_key' => 'secret-value',
            'message' => 'Authorization: Bearer abc.def.ghi',
        ],
    ];
    $redacted = SensitiveDataRedactor::redact($input);

    assertSameValue('account-reference', $redacted['username'], 'non-secret account reference remains');
    assertSameValue('[REDACTED]', $redacted['password'], 'password is redacted');
    assertSameValue('[REDACTED]', $redacted['nested']['api_key'], 'api key is redacted');
    assertTrueValue(
        strpos($redacted['nested']['message'], 'abc.def.ghi') === false,
        'bearer token is removed from text'
    );
};

$tests['health checks aggregate without exposing errors'] = static function (): void {
    requireClass(HealthCheckService::class);

    $healthy = new HealthCheckService(
        static function (): void {
        },
        static function (): void {
        }
    );
    $healthyReport = $healthy->check();
    assertSameValue('ok', $healthyReport['status'], 'healthy status');
    assertSameValue(200, $healthyReport['http_status'], 'healthy HTTP status');

    $degraded = new HealthCheckService(
        static function (): void {
            throw new RuntimeException('contains-sensitive-database-detail');
        },
        static function (): void {
        }
    );
    $degradedReport = $degraded->check();
    assertSameValue('degraded', $degradedReport['status'], 'degraded status');
    assertSameValue(503, $degradedReport['http_status'], 'degraded HTTP status');
    assertSameValue('unavailable', $degradedReport['dependencies']['database'], 'database state');
    assertTrueValue(
        strpos(json_encode($degradedReport), 'contains-sensitive-database-detail') === false,
        'raw dependency errors must not be exposed'
    );
};

$tests['queue policy is bounded and deterministic'] = static function (): void {
    requireClass(QueuePolicy::class);

    $policy = new QueuePolicy(3, [5, 30, 120], 64);
    assertSameValue(3, $policy->maxAttempts(), 'maximum attempts');
    assertSameValue(5, $policy->delayForAttempt(1), 'first retry delay');
    assertSameValue(30, $policy->delayForAttempt(2), 'second retry delay');
    assertSameValue(120, $policy->delayForAttempt(99), 'delay remains bounded');
    assertSameValue(64, $policy->maxPayloadBytes(), 'payload limit');
};

$tests['generic queue job deletes on success and releases retryable failures'] = static function (): void {
    requireClass(AbstractQueueJob::class);
    requireClass(RetryableJobException::class);

    $makeJob = static function (int $attempts): Job {
        return new class($attempts) extends Job {
            public $releasedDelay;
            private $attemptCount;

            public function __construct(int $attempts)
            {
                $this->attemptCount = $attempts;
            }

            public function fire()
            {
            }

            public function attempts()
            {
                return $this->attemptCount;
            }

            public function getRawBody()
            {
                return '{}';
            }

            public function release($delay = 0)
            {
                parent::release($delay);
                $this->releasedDelay = $delay;
            }
        };
    };

    $successful = new class extends AbstractQueueJob {
        protected function handle(array $payload): void
        {
            if (($payload['kind'] ?? '') !== 'foundation') {
                throw new RuntimeException('unexpected payload');
            }
        }

        protected function policy(): QueuePolicy
        {
            return new QueuePolicy(3, [5, 30, 120], 256);
        }
    };
    $successfulJob = $makeJob(1);
    $successful->fire($successfulJob, ['kind' => 'foundation']);
    assertTrueValue($successfulJob->isDeleted(), 'successful job is deleted');
    assertTrueValue(!$successfulJob->isReleased(), 'successful job is not released');

    $retryable = new class extends AbstractQueueJob {
        protected function handle(array $payload): void
        {
            throw new RetryableJobException('temporary secret-bearing failure');
        }

        protected function policy(): QueuePolicy
        {
            return new QueuePolicy(3, [5, 30, 120], 256);
        }
    };
    $retryJob = $makeJob(2);
    $retryable->fire($retryJob, ['kind' => 'foundation']);
    assertTrueValue($retryJob->isReleased(), 'retryable job is released');
    assertSameValue(30, $retryJob->releasedDelay, 'retry delay follows policy');

    $exhaustedJob = $makeJob(3);
    $retryable->fire($exhaustedJob, ['kind' => 'foundation']);
    assertTrueValue($exhaustedJob->isDeleted(), 'exhausted job is deleted');
    assertTrueValue(!$exhaustedJob->isReleased(), 'exhausted job is not released');
};

$tests['generic queue job rejects oversized payloads'] = static function (): void {
    requireClass(AbstractQueueJob::class);

    $handler = new class extends AbstractQueueJob {
        protected function handle(array $payload): void
        {
            throw new RuntimeException('oversized payload reached handler');
        }

        protected function policy(): QueuePolicy
        {
            return new QueuePolicy(3, [5, 30, 120], 32);
        }
    };

    $job = new class extends Job {
        public function fire()
        {
        }

        public function attempts()
        {
            return 1;
        }

        public function getRawBody()
        {
            return '{}';
        }
    };

    $thrown = false;
    try {
        $handler->fire($job, ['content' => str_repeat('x', 64)]);
    } catch (InvalidArgumentException $exception) {
        $thrown = true;
    }
    assertTrueValue($thrown, 'oversized payload must fail closed');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name}: {$exception->getMessage()}\n");
    }
}

if ($failed > 0) {
    fwrite(STDERR, "RESULT: {$failed} failed, " . (count($tests) - $failed) . " passed\n");
    exit(1);
}

fwrite(STDOUT, 'RESULT: ' . count($tests) . " passed, 0 failed\n");
