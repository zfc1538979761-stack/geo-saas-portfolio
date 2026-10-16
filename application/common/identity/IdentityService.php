<?php

declare(strict_types=1);

namespace app\common\identity;

final class IdentityService
{
    private const GENERIC_LOGIN_MESSAGE = '账号或密码错误';
    private const INVALID_SESSION_MESSAGE = '登录状态无效或已过期';
    private const DUMMY_PASSWORD_HASH = '$2y$10$hZqYJXhglhmf4QvB3Sy0.OIhW9fM7BJVDOkB7DgXw8dA4r4LCAXEy';

    private $repository;
    private $tokenStore;
    private $rateLimiter;
    private $securityLogger;
    private $passwordHasher;
    private $tokenTtlSeconds;
    private $tokenGenerator;
    private $clock;
    private $transaction;

    public function __construct(
        IdentityRepository $repository,
        AuthTokenStore $tokenStore,
        LoginRateLimiter $rateLimiter,
        SecurityEventLogger $securityLogger,
        PasswordHasher $passwordHasher,
        int $tokenTtlSeconds = 3600,
        ?callable $tokenGenerator = null,
        ?callable $clock = null,
        ?IdentityTransaction $transaction = null
    ) {
        if ($tokenTtlSeconds < 1) {
            throw new \InvalidArgumentException('Token lifetime must be positive.');
        }

        $this->repository = $repository;
        $this->tokenStore = $tokenStore;
        $this->rateLimiter = $rateLimiter;
        $this->securityLogger = $securityLogger;
        $this->passwordHasher = $passwordHasher;
        $this->tokenTtlSeconds = $tokenTtlSeconds;
        $this->tokenGenerator = $tokenGenerator ?: static function (): string {
            return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        };
        $this->clock = $clock ?: static function (): int {
            return time();
        };
        $this->transaction = $transaction ?: new SynchronousIdentityTransaction();
    }

    public function login(string $account, string $password, string $ip, string $requestId): array
    {
        $account = trim($account);
        if ($account === '' || $password === '') {
            throw new IdentityException(1001, '请求参数无效', 422);
        }

        $this->rateLimiter->assertAllowed($account, $ip);
        $identity = $this->repository->findByAccount($account);
        $passwordHash = $identity['password_hash'] ?? self::DUMMY_PASSWORD_HASH;
        $validPassword = $this->passwordHasher->verify($password, (string) $passwordHash);
        $active = $identity !== null
            && ($identity['status'] ?? '') === 'normal'
            && ($identity['company_status'] ?? '') === 'normal'
            && (int) ($identity['company_id'] ?? 0) > 0;

        if (!$validPassword || !$active) {
            $this->securityLogger->record('enterprise_login_failed', [
                'request_id' => $requestId,
                'ip' => $ip,
                'user_id' => $identity['id'] ?? null,
                'company_id' => $identity['company_id'] ?? null,
                'occurred_at' => $this->now(),
            ]);
            throw new IdentityException(1401, self::GENERIC_LOGIN_MESSAGE, 401);
        }

        $this->rateLimiter->clear($account, $ip);
        $token = (string) call_user_func($this->tokenGenerator);
        if ($token === '') {
            throw new \RuntimeException('Token generation failed.');
        }
        $userId = (int) $identity['id'];
        $now = $this->now();
        $this->tokenStore->put($token, $userId, $this->tokenTtlSeconds);
        $this->repository->recordSuccessfulLogin($userId, $ip, $now);
        $this->securityLogger->record('enterprise_login_succeeded', [
            'request_id' => $requestId,
            'ip' => $ip,
            'user_id' => $userId,
            'company_id' => (int) $identity['company_id'],
            'occurred_at' => $now,
        ]);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->tokenTtlSeconds,
            'user' => $this->sanitize($identity),
        ];
    }

    public function current(string $token): array
    {
        [$identity] = $this->resolveActiveIdentity($token);
        return $this->sanitize($identity);
    }

    public function changePassword(string $token, string $currentPassword, string $newPassword): void
    {
        [$identity] = $this->resolveActiveIdentity($token);
        if (!$this->passwordHasher->verify($currentPassword, (string) $identity['password_hash'])) {
            throw new IdentityException(1401, '当前密码错误', 401);
        }
        $length = strlen($newPassword);
        if ($length < 12 || $length > 72) {
            throw new IdentityException(1001, '新密码必须为12至72个字符', 422);
        }

        $userId = (int) $identity['id'];
        $newPasswordHash = $this->passwordHasher->hash($newPassword);
        $updatedAt = $this->now();
        $this->transaction->run(function () use ($userId, $newPasswordHash, $updatedAt): void {
            $this->repository->updatePassword($userId, $newPasswordHash, $updatedAt);
            $this->tokenStore->clearUser($userId);
        });
        $this->securityLogger->record('enterprise_password_changed', [
            'user_id' => $userId,
            'company_id' => (int) $identity['company_id'],
            'occurred_at' => $updatedAt,
        ]);
    }

    public function logout(string $token): void
    {
        $tokenData = $token === '' ? null : $this->tokenStore->get($token);
        $this->tokenStore->delete($token);
        $this->securityLogger->record('enterprise_logout', [
            'user_id' => $tokenData['user_id'] ?? null,
            'occurred_at' => $this->now(),
        ]);
    }

    private function resolveActiveIdentity(string $token): array
    {
        if ($token === '') {
            throw new IdentityException(1401, self::INVALID_SESSION_MESSAGE, 401);
        }
        $tokenData = $this->tokenStore->get($token);
        if (
            $tokenData === null
            || (int) ($tokenData['user_id'] ?? 0) < 1
            || (int) ($tokenData['expiretime'] ?? 0) <= $this->now()
        ) {
            $this->tokenStore->delete($token);
            throw new IdentityException(1401, self::INVALID_SESSION_MESSAGE, 401);
        }

        $identity = $this->repository->findById((int) $tokenData['user_id']);
        $active = $identity !== null
            && ($identity['status'] ?? '') === 'normal'
            && ($identity['company_status'] ?? '') === 'normal'
            && (int) ($identity['company_id'] ?? 0) > 0;
        if (!$active) {
            $this->tokenStore->delete($token);
            throw new IdentityException(1401, self::INVALID_SESSION_MESSAGE, 401);
        }

        return [$identity, $tokenData];
    }

    private function sanitize(array $identity): array
    {
        return [
            'id' => (int) $identity['id'],
            'username' => (string) $identity['username'],
            'role' => (string) $identity['role'],
            'status' => (string) $identity['status'],
            'company' => [
                'id' => (int) $identity['company_id'],
                'name' => (string) $identity['company_name'],
                'status' => (string) $identity['company_status'],
            ],
        ];
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
