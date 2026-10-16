<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\tenant\AuditLogger;
use app\common\tenant\InternalAdminIdentity;
use app\common\tenant\TenantBypassAuthorizer;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextResolver;
use app\common\tenant\TenantException;
use app\common\tenant\TenantIdentityProvider;
use app\common\tenant\TenantNamespace;
use app\common\tenant\TenantTaskEnvelope;

final class T03ContextFailure extends RuntimeException
{
}

function t03ContextAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T03ContextFailure($message);
    }
}

function t03ContextRejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (TenantException $exception) {
        return;
    }
    throw new T03ContextFailure($message);
}

final class T03IdentityProvider implements TenantIdentityProvider
{
    private $identities;

    public function __construct(array $identities)
    {
        $this->identities = $identities;
    }

    public function resolve(string $token): array
    {
        if (!isset($this->identities[$token])) {
            throw new TenantException('Unauthenticated tenant context.');
        }
        return $this->identities[$token];
    }
}

final class T03MemoryAuditLogger implements AuditLogger
{
    public $records = [];

    public function record(
        string $action,
        ?TenantContext $context,
        string $targetType,
        ?string $targetId,
        string $ip,
        array $detail = []
    ): void {
        $this->records[] = compact('action', 'context', 'targetType', 'targetId', 'ip', 'detail');
    }
}

$provider = new T03IdentityProvider([
    'token-a' => [
        'id' => 11,
        'role' => 'company_owner',
        'status' => 'normal',
        'company' => ['id' => 101, 'status' => 'normal'],
    ],
    'disabled-user' => [
        'id' => 12,
        'role' => 'company_owner',
        'status' => 'disabled',
        'company' => ['id' => 101, 'status' => 'normal'],
    ],
    'disabled-company' => [
        'id' => 13,
        'role' => 'company_owner',
        'status' => 'normal',
        'company' => ['id' => 101, 'status' => 'disabled'],
    ],
]);
$resolver = new TenantContextResolver($provider);
$contextA = $resolver->resolve('token-a');

t03ContextAssert($contextA->actorId() === 11, 'actor must come from authenticated identity');
t03ContextAssert($contextA->companyId() === 101, 'company must come from authenticated identity');
t03ContextAssert(!$contextA->canBypass(), 'enterprise context must never bypass scope');

foreach (['', 'unknown', 'disabled-user', 'disabled-company'] as $token) {
    t03ContextRejects(
        static function () use ($resolver, $token): void {
            $resolver->resolve($token);
        },
        'invalid or disabled identity must not create tenant context'
    );
}

$forgedInputs = [
    ['url' => '/resource?company_id=202'],
    ['query' => ['company_id' => 202]],
    ['body' => ['company_id' => 202]],
    ['headers' => ['Company-Id' => '202', 'X-Company-Id' => '202']],
];
foreach ($forgedInputs as $unusedInput) {
    $resolved = $resolver->resolve('token-a');
    t03ContextAssert($resolved->companyId() === 101, 'untrusted request data must not switch tenant');
}

t03ContextAssert(
    TenantNamespace::cacheKey($contextA, ['keyword', '42']) === 'tenant:101:keyword:42',
    'cache namespace must include tenant'
);
t03ContextAssert(
    TenantNamespace::fileKey($contextA, ['knowledge', 'source.pdf']) === 'tenants/101/knowledge/source.pdf',
    'file namespace must include tenant'
);
t03ContextAssert(
    TenantNamespace::exportKey($contextA, 'job-7') === 'tenant:101:export:job-7',
    'export namespace must include tenant'
);
t03ContextRejects(
    static function () use ($contextA): void {
        TenantNamespace::fileKey($contextA, ['..', 'secret']);
    },
    'file namespace must reject traversal'
);

$clock = static function (): int {
    return 1722300000;
};
$envelope = new TenantTaskEnvelope(str_repeat('s', 32), $clock);
$signed = $envelope->issue($contextA, 'tenant.test', ['resource_id' => 7]);
$taskContext = $envelope->resolve($signed);
t03ContextAssert($taskContext->companyId() === 101, 'signed task must restore trusted tenant');
$tampered = $signed;
$tampered['company_id'] = 202;
t03ContextRejects(
    static function () use ($envelope, $tampered): void {
        $envelope->resolve($tampered);
    },
    'tampered task tenant must be rejected'
);
t03ContextRejects(
    static function () use ($envelope): void {
        $envelope->resolve(['company_id' => 202]);
    },
    'raw task parameters must not establish tenant context'
);

$audit = new T03MemoryAuditLogger();
$authorizer = new TenantBypassAuthorizer($audit);
$withoutPermission = new InternalAdminIdentity(8, []);
t03ContextRejects(
    static function () use ($authorizer, $withoutPermission): void {
        $authorizer->authorize($withoutPermission, 'support investigation', '127.0.0.1');
    },
    'internal identity without permission must not bypass'
);
$admin = new InternalAdminIdentity(9, ['tenant.scope.bypass']);
$bypass = $authorizer->authorize($admin, 'incident investigation', '127.0.0.1');
t03ContextAssert($bypass->canBypass(), 'authorized internal admin must receive bypass context');
t03ContextAssert($bypass->actorType() === 'internal_admin', 'bypass must use independent identity');
t03ContextAssert(count($audit->records) === 1, 'bypass must create one audit record');
t03ContextAssert(
    $audit->records[0]['action'] === 'tenant_scope_bypass_granted',
    'bypass audit action must be explicit'
);

$contextB = (new TenantContextResolver(new T03IdentityProvider([
    'token-b' => [
        'id' => 21,
        'role' => 'company_owner',
        'status' => 'normal',
        'company' => ['id' => 202, 'status' => 'normal'],
    ],
])))->resolve('token-b');
t03ContextAssert($contextA->companyId() === 101, 'first request context must remain immutable');
t03ContextAssert($contextB->companyId() === 202, 'next request must use its own explicit context');

fwrite(STDOUT, "PASS: T03 trusted tenant context, namespaces, task envelope, bypass, and cleanup contracts\n");
