<?php

declare(strict_types=1);

namespace app\common\identity;

use think\Config;

final class IdentityServiceFactory
{
    public static function create(): IdentityService
    {
        $config = Config::get('geo_identity');
        $tokenConfig = Config::get('token');

        return new IdentityService(
            new ThinkIdentityRepository(),
            new ThinkAuthTokenStore(),
            new LoginRateLimiter(
                new ThinkLoginAttemptStore(),
                (string) ($tokenConfig['key'] ?? ''),
                (int) ($config['maximum_login_failures'] ?? 5),
                (int) ($config['login_failure_window_seconds'] ?? 900),
                (int) ($config['login_lockout_seconds'] ?? 900)
            ),
            new ThinkSecurityEventLogger(),
            new PasswordHasher(),
            (int) ($config['token_ttl_seconds'] ?? 3600),
            null,
            null,
            new ThinkIdentityTransaction()
        );
    }

    private function __construct()
    {
    }
}
