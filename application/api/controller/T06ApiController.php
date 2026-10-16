<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\content\ContentAuthenticationException;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextFactory;
use app\common\tenant\TenantException;
use think\Request;

abstract class T06ApiController extends ContentApiController
{
    protected function context(Request $request): TenantContext
    {
        try { return TenantContextFactory::fromEnterpriseToken($this->bearerToken($request)); }
        catch (ContentAuthenticationException $exception) { throw $exception; }
        catch (TenantException $exception) { throw new ContentAuthenticationException('Unauthenticated tenant context.'); }
    }

    protected function ids($value): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        if (!is_array($value)) { return []; }
        return $value;
    }
}
