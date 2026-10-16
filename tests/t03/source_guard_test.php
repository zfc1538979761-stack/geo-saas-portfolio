<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextFactory;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantSchemaGuard;

final class T03SourceGuardFailure extends RuntimeException
{
}

function t03SourceGuardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T03SourceGuardFailure($message);
    }
}

$contextReflection = new ReflectionClass(TenantContext::class);
t03SourceGuardAssert(
    $contextReflection->getConstructor() !== null && $contextReflection->getConstructor()->isPrivate(),
    'TenantContext constructor must not be public'
);

$factoryMethod = new ReflectionMethod(TenantContextFactory::class, 'fromEnterpriseToken');
t03SourceGuardAssert(
    $factoryMethod->getNumberOfParameters() === 1
    && $factoryMethod->getParameters()[0]->getName() === 'token',
    'request factory may accept only a trusted authentication token'
);

$gatewayMethods = array_map(static function (ReflectionMethod $method): string {
    return $method->getName();
}, (new ReflectionClass(TenantDataGateway::class))->getMethods(ReflectionMethod::IS_PUBLIC));
foreach ([
    'insert',
    'find',
    'list',
    'update',
    'delete',
    'updateMany',
    'deleteMany',
    'count',
    'sum',
    'search',
    'joinList',
    'whereRelatedExists',
] as $requiredMethod) {
    t03SourceGuardAssert(in_array($requiredMethod, $gatewayMethods, true), 'tenant gateway contract is incomplete');
}
foreach (['raw', 'query', 'execute', 'withoutScope', 'ignoreScope'] as $forbiddenMethod) {
    t03SourceGuardAssert(
        !in_array($forbiddenMethod, $gatewayMethods, true),
        'tenant gateway must not expose an unscoped SQL escape hatch'
    );
}

$tenantFiles = glob(APP_PATH . 'common' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . '*.php') ?: [];
t03SourceGuardAssert($tenantFiles !== [], 'tenant infrastructure files must exist');
foreach ($tenantFiles as $file) {
    $source = file_get_contents($file);
    t03SourceGuardAssert(is_string($source), 'tenant infrastructure source must be readable');
    t03SourceGuardAssert(
        preg_match('/(?:public|protected|private)?\s+static\s+\$[A-Za-z_]/', $source) !== 1,
        'tenant infrastructure must not store request or task state in static properties'
    );
    t03SourceGuardAssert(
        stripos($source, 'ignore_scope') === false && stripos($source, 'x-company-id') === false,
        'tenant bypass must not be enabled by request parameters or headers'
    );
}

$allowedEnterpriseFactories = [
    'TenantContext.php',
    'TenantContextResolver.php',
    'TenantTaskEnvelope.php',
];
$applicationPhp = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(APP_PATH, FilesystemIterator::SKIP_DOTS)
);
foreach ($applicationPhp as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if (!is_string($source)) {
        throw new T03SourceGuardFailure('application source must be readable');
    }
    if (
        strpos($source, 'TenantContext::enterprise(') !== false
        && !in_array($file->getFilename(), $allowedEnterpriseFactories, true)
    ) {
        throw new T03SourceGuardFailure(
            'production code may not construct enterprise tenant context outside trusted resolvers'
        );
    }
    if (
        strpos($source, 'TenantContext::authorizeInternalBypass(') !== false
        && $file->getFilename() !== 'TenantBypassAuthorizer.php'
    ) {
        throw new T03SourceGuardFailure(
            'production code may not bypass the controlled internal authorizer'
        );
    }
}

$unsafeMigration = <<<'PHP'
<?php
// future migration
$pdo->exec('CREATE TABLE future_keywords (`id` BIGINT PRIMARY KEY)');
PHP;
t03SourceGuardAssert(
    TenantSchemaGuard::inspectMigration('202607300004_future.php', $unsafeMigration, []) !== [],
    'future tables without a tenant/global declaration must fail the schema guard'
);
$unregisteredTenantMigration = <<<'PHP'
<?php
// @tenant-resource keywords
$pdo->exec(
    'CREATE TABLE future_keywords ('
    . '`id` BIGINT PRIMARY KEY, `company_id` BIGINT NOT NULL,'
    . 'KEY `idx_company_created` (`company_id`,`id`),'
    . 'FOREIGN KEY (`company_id`) REFERENCES companies (`id`)'
    . ')'
);
PHP;
t03SourceGuardAssert(
    TenantSchemaGuard::inspectMigration('202607300004_future.php', $unregisteredTenantMigration, []) !== [],
    'future tenant tables not in the central catalog must fail the schema guard'
);
t03SourceGuardAssert(
    TenantSchemaGuard::inspectMigration(
        '202607300004_future.php',
        $unregisteredTenantMigration,
        ['keywords']
    ) === [],
    'declared tenant tables with company_id and catalog registration pass the schema guard'
);

fwrite(STDOUT, "PASS: T03 source guard rejects unscoped escape hatches and static tenant state\n");
