<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);

require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\identity\AuthTokenStore;
use app\common\identity\IdentityException;
use app\common\identity\IdentityRepository;
use app\common\identity\IdentityService;
use app\common\identity\IdentityTransaction;
use app\common\identity\LoginAttemptStore;
use app\common\identity\LoginRateLimiter;
use app\common\identity\PasswordHasher;
use app\common\identity\SecurityEventLogger;

final class T02IdentityTestFailure extends RuntimeException
{
}

function t02Fail(string $message): void
{
    throw new T02IdentityTestFailure($message);
}

function t02AssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        t02Fail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function t02AssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        t02Fail($message);
    }
}

function t02ExpectIdentityError(callable $callback, int $code, string $message): void
{
    try {
        $callback();
    } catch (IdentityException $exception) {
        t02AssertSame($code, $exception->errorCode(), $message . ' code');
        return;
    }

    t02Fail($message . ' did not throw');
}

$requiredClasses = [
    PasswordHasher::class,
    LoginRateLimiter::class,
    IdentityService::class,
    IdentityException::class,
    IdentityRepository::class,
    AuthTokenStore::class,
    LoginAttemptStore::class,
    SecurityEventLogger::class,
    IdentityTransaction::class,
];
foreach ($requiredClasses as $requiredClass) {
    if (!class_exists($requiredClass) && !interface_exists($requiredClass)) {
        fwrite(STDERR, "FAIL: missing T02 identity class {$requiredClass}\n");
        exit(1);
    }
}

final class ArrayLoginAttemptStore implements LoginAttemptStore
{
    public $rows = [];

    public function reserveAttempt(
        string $key,
        int $now,
        int $maximumFailures,
        int $windowSeconds,
        int $lockoutSeconds
    ): bool
    {
        $state = $this->rows[$key] ?? null;
        if (
            $state === null
            || (int) ($state['window_started_at'] ?? 0) + $windowSeconds <= $now
        ) {
            $state = [
                'failures' => 0,
                'window_started_at' => $now,
                'blocked_until' => 0,
            ];
        }
        if ((int) $state['blocked_until'] > $now) {
            return false;
        }
        if ((int) $state['failures'] >= $maximumFailures) {
            $state['blocked_until'] = $now + $lockoutSeconds;
            $this->rows[$key] = $state;
            return false;
        }
        $state['failures'] = (int) $state['failures'] + 1;
        $this->rows[$key] = $state;
        return true;
    }

    public function delete(string $key): void
    {
        unset($this->rows[$key]);
    }
}

final class ArrayIdentityRepository implements IdentityRepository
{
    public $identities = [];
    public $loginSuccesses = [];
    public $accountLookups = 0;

    public function findByAccount(string $account): ?array
    {
        $this->accountLookups++;
        foreach ($this->identities as $identity) {
            if ($identity['username'] === $account) {
                return $identity;
            }
        }
        return null;
    }

    public function findById(int $userId): ?array
    {
        return $this->identities[$userId] ?? null;
    }

    public function recordSuccessfulLogin(int $userId, string $ip, int $occurredAt): void
    {
        $this->loginSuccesses[] = [$userId, $ip, $occurredAt];
    }

    public function updatePassword(int $userId, string $passwordHash, int $updatedAt): void
    {
        $this->identities[$userId]['password_hash'] = $passwordHash;
        $this->identities[$userId]['updatetime'] = $updatedAt;
    }
}

final class ArrayAuthTokenStore implements AuthTokenStore
{
    public $tokens = [];
    public $throwOnClear = false;

    public function put(string $token, int $userId, int $ttlSeconds): void
    {
        $this->tokens[$token] = [
            'user_id' => $userId,
            'expiretime' => 1700000000 + $ttlSeconds,
        ];
    }

    public function get(string $token): ?array
    {
        return $this->tokens[$token] ?? null;
    }

    public function delete(string $token): void
    {
        unset($this->tokens[$token]);
    }

    public function clearUser(int $userId): void
    {
        if ($this->throwOnClear) {
            throw new RuntimeException('simulated token revocation failure');
        }
        foreach ($this->tokens as $token => $data) {
            if ($data['user_id'] === $userId) {
                unset($this->tokens[$token]);
            }
        }
    }
}

final class SnapshotIdentityTransaction implements IdentityTransaction
{
    private $repository;
    private $tokens;

    public function __construct(ArrayIdentityRepository $repository, ArrayAuthTokenStore $tokens)
    {
        $this->repository = $repository;
        $this->tokens = $tokens;
    }

    public function run(callable $operation)
    {
        $identities = $this->repository->identities;
        $tokens = $this->tokens->tokens;
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->repository->identities = $identities;
            $this->tokens->tokens = $tokens;
            throw $exception;
        }
    }
}

final class ArraySecurityEventLogger implements SecurityEventLogger
{
    public $events = [];

    public function record(string $event, array $context): void
    {
        $this->events[] = [$event, $context];
    }
}

function t02IdentityFixture(array $overrides = []): array
{
    $hasher = new PasswordHasher();
    return array_merge([
        'id' => 7,
        'company_id' => 23,
        'username' => 'owner@example',
        'password_hash' => $hasher->hash('Correct-Horse-2026'),
        'role' => 'company_owner',
        'status' => 'normal',
        'company_name' => '示例企业',
        'company_status' => 'normal',
        'createtime' => 1700000000,
        'updatetime' => 1700000000,
    ], $overrides);
}

function t02ServiceFixture(array $identityOverrides = []): array
{
    $repository = new ArrayIdentityRepository();
    $repository->identities[7] = t02IdentityFixture($identityOverrides);
    $tokens = new ArrayAuthTokenStore();
    $attempts = new ArrayLoginAttemptStore();
    $logger = new ArraySecurityEventLogger();
    $now = 1700000000;
    $limiter = new LoginRateLimiter($attempts, 'test-only-rate-limit-secret', 5, 900, 900, static function () use (&$now): int {
        return $now;
    });
    $service = new IdentityService(
        $repository,
        $tokens,
        $limiter,
        $logger,
        new PasswordHasher(),
        3600,
        static function (): string {
            return 'fresh-random-token';
        },
        static function () use (&$now): int {
            return $now;
        },
        new SnapshotIdentityTransaction($repository, $tokens)
    );

    return [$service, $repository, $tokens, $attempts, $logger, &$now];
}

$tests = [];

$tests['passwords use a one-way framework hash'] = static function (): void {
    $hasher = new PasswordHasher();
    $hash = $hasher->hash('Correct-Horse-2026');

    t02AssertTrue($hash !== 'Correct-Horse-2026', 'password must not be stored as plaintext');
    t02AssertTrue(strlen($hash) > 32, 'password hash must not use the legacy MD5 field');
    t02AssertTrue($hasher->verify('Correct-Horse-2026', $hash), 'correct password verifies');
    t02AssertTrue(!$hasher->verify('wrong-password', $hash), 'wrong password is rejected');
};

$tests['correct credentials issue a fresh token and trusted company context'] = static function (): void {
    [$service, $repository, $tokens] = t02ServiceFixture();

    $result = $service->login('owner@example', 'Correct-Horse-2026', '127.0.0.1', 'request-login');

    t02AssertSame('fresh-random-token', $result['token'], 'login token');
    t02AssertSame(23, $result['user']['company']['id'], 'company id comes from repository identity');
    t02AssertSame('示例企业', $result['user']['company']['name'], 'company name');
    t02AssertTrue(isset($tokens->tokens['fresh-random-token']), 'token is persisted');
    t02AssertSame([[7, '127.0.0.1', 1700000000]], $repository->loginSuccesses, 'login metadata');
};

$tests['wrong account and wrong password are indistinguishable'] = static function (): void {
    [$service] = t02ServiceFixture();
    $messages = [];
    foreach ([
        ['missing@example', 'Correct-Horse-2026'],
        ['owner@example', 'wrong-password'],
    ] as $attempt) {
        try {
            $service->login($attempt[0], $attempt[1], '127.0.0.1', 'request-failed');
        } catch (IdentityException $exception) {
            $messages[] = [$exception->errorCode(), $exception->getMessage()];
        }
    }

    t02AssertSame(2, count($messages), 'both invalid login attempts fail');
    t02AssertSame($messages[0], $messages[1], 'invalid login responses are identical');
};

$tests['disabled users and disabled companies cannot login'] = static function (): void {
    foreach ([
        ['status' => 'disabled'],
        ['company_status' => 'disabled'],
    ] as $overrides) {
        [$service] = t02ServiceFixture($overrides);
        t02ExpectIdentityError(
            static function () use ($service): void {
                $service->login('owner@example', 'Correct-Horse-2026', '127.0.0.1', 'request-disabled');
            },
            1401,
            'disabled identity login'
        );
    }
};

$tests['five failures throttle further attempts without storing the account'] = static function (): void {
    [$service, $repository, , $attempts] = t02ServiceFixture();
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        t02ExpectIdentityError(
            static function () use ($service): void {
                $service->login('missing@example', 'wrong-password', '127.0.0.1', 'request-throttle');
            },
            1401,
            'failed login before throttle'
        );
    }

    t02ExpectIdentityError(
        static function () use ($service): void {
            $service->login('missing@example', 'wrong-password', '127.0.0.1', 'request-throttled');
        },
        1429,
        'throttled login'
    );
    $serialized = json_encode($attempts->rows);
    t02AssertTrue(strpos($serialized, 'missing@example') === false, 'throttle state must not store raw account');
    t02AssertSame(5, $repository->accountLookups, 'sixth attempt is rejected before credential lookup');
};

$tests['expired or unknown tokens fail protected identity access'] = static function (): void {
    [$service, , $tokens] = t02ServiceFixture();
    $tokens->tokens['expired-token'] = ['user_id' => 7, 'expiretime' => 1699999999];

    t02ExpectIdentityError(
        static function () use ($service): void {
            $service->current('expired-token');
        },
        1401,
        'expired token'
    );
    t02ExpectIdentityError(
        static function () use ($service): void {
            $service->current('missing-token');
        },
        1401,
        'unknown token'
    );
};

$tests['disabling an authenticated user or company invalidates the old token'] = static function (): void {
    foreach ([
        ['status' => 'disabled'],
        ['company_status' => 'disabled'],
    ] as $overrides) {
        [$service, , $tokens] = t02ServiceFixture($overrides);
        $tokens->tokens['old-token'] = ['user_id' => 7, 'expiretime' => 1700003600];

        t02ExpectIdentityError(
            static function () use ($service): void {
                $service->current('old-token');
            },
            1401,
            'disabled authenticated identity'
        );
        t02AssertTrue(!isset($tokens->tokens['old-token']), 'disabled identity token is revoked');
    }
};

$tests['current identity is sanitized and cannot switch company'] = static function (): void {
    [$service, , $tokens] = t02ServiceFixture();
    $tokens->tokens['valid-token'] = ['user_id' => 7, 'expiretime' => 1700003600];

    $current = $service->current('valid-token');
    $encoded = json_encode($current, JSON_UNESCAPED_UNICODE);

    t02AssertSame(23, $current['company']['id'], 'trusted company id');
    foreach (['password', 'password_hash', 'token', 'mobile', 'email'] as $forbidden) {
        t02AssertTrue(strpos($encoded, $forbidden) === false, "current identity excludes {$forbidden}");
    }
};

$tests['password change verifies old password and revokes every session'] = static function (): void {
    [$service, $repository, $tokens] = t02ServiceFixture();
    $tokens->tokens = [
        'session-a' => ['user_id' => 7, 'expiretime' => 1700003600],
        'session-b' => ['user_id' => 7, 'expiretime' => 1700003600],
    ];

    t02ExpectIdentityError(
        static function () use ($service): void {
            $service->changePassword('session-a', 'wrong-password', 'Replacement-Password-2026');
        },
        1401,
        'wrong current password'
    );
    $service->changePassword('session-a', 'Correct-Horse-2026', 'Replacement-Password-2026');

    t02AssertSame([], $tokens->tokens, 'all old sessions are revoked');
    $hasher = new PasswordHasher();
    t02AssertTrue(
        $hasher->verify('Replacement-Password-2026', $repository->identities[7]['password_hash']),
        'new password is stored safely'
    );
    t02AssertTrue(
        !$hasher->verify('Correct-Horse-2026', $repository->identities[7]['password_hash']),
        'old password no longer works'
    );
};

$tests['password change rolls back when session revocation fails'] = static function (): void {
    [$service, $repository, $tokens] = t02ServiceFixture();
    $originalHash = $repository->identities[7]['password_hash'];
    $tokens->tokens = [
        'session-a' => ['user_id' => 7, 'expiretime' => 1700003600],
    ];
    $tokens->throwOnClear = true;

    try {
        $service->changePassword('session-a', 'Correct-Horse-2026', 'Replacement-Password-2026');
        t02Fail('password change must fail when session revocation fails');
    } catch (RuntimeException $exception) {
        t02AssertSame(
            'simulated token revocation failure',
            $exception->getMessage(),
            'revocation failure is propagated'
        );
    }

    t02AssertSame(
        $originalHash,
        $repository->identities[7]['password_hash'],
        'password update is rolled back with token revocation'
    );
    t02AssertTrue(isset($tokens->tokens['session-a']), 'existing session state is rolled back');
};

$tests['logout revokes only the presented token'] = static function (): void {
    [$service, , $tokens] = t02ServiceFixture();
    $tokens->tokens = [
        'session-a' => ['user_id' => 7, 'expiretime' => 1700003600],
        'session-b' => ['user_id' => 7, 'expiretime' => 1700003600],
    ];

    $service->logout('session-a');

    t02AssertTrue(!isset($tokens->tokens['session-a']), 'presented token is revoked');
    t02AssertTrue(isset($tokens->tokens['session-b']), 'other session remains until password/security event');
};

$tests['security events never contain password or token values'] = static function (): void {
    [$service, , , , $logger] = t02ServiceFixture();
    $service->login('owner@example', 'Correct-Horse-2026', '127.0.0.1', 'request-log');
    $encoded = json_encode($logger->events, JSON_UNESCAPED_UNICODE);

    foreach (['Correct-Horse-2026', 'fresh-random-token', 'password_hash'] as $forbidden) {
        t02AssertTrue(strpos($encoded, $forbidden) === false, "security log excludes {$forbidden}");
    }
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
