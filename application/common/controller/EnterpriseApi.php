<?php

declare(strict_types=1);

namespace app\common\controller;

use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextFactory;
use app\common\tenant\TenantException;
use think\Request;

/**
 * Enterprise-only API base.
 *
 * It intentionally does not initialize FastAdmin's legacy member Auth. The
 * only accepted identity is the T02 enterprise token, resolved through the
 * trusted TenantContextFactory path.
 */
class EnterpriseApi extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = [];

    protected function _initialize()
    {
        check_cors_request();
        check_ip_allowed();
        $this->request->filter('trim,strip_tags,htmlspecialchars');
    }

    protected function context(): TenantContext
    {
        try {
            return TenantContextFactory::fromEnterpriseToken($this->bearerToken());
        } catch (TenantException $exception) {
            $this->error('登录状态无效或已过期。', null, 401);
        }
    }

    protected function bearerToken(): string
    {
        $authorization = trim((string) $this->request->server('HTTP_AUTHORIZATION', ''));
        if (preg_match('/^Bearer\s+([A-Za-z0-9._~-]{20,200})$/i', $authorization, $matches) === 1) {
            return $matches[1];
        }
        $token = trim((string) $this->request->server('HTTP_TOKEN', ''));
        if (preg_match('/^[A-Za-z0-9._~-]{20,200}$/', $token) === 1) {
            return $token;
        }

        $this->error('登录状态无效或已过期。', null, 401);
    }
}
