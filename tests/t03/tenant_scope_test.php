<?php

declare(strict_types=1);

if (getenv('GEO_T03_SCOPE_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T03 A/B tenant scope test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T03_SCOPE_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T03_SCOPE_TEST_PREFIX') !== 't03_scope_'
) {
    fwrite(STDERR, "FAIL: T03 tenant scope safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\tenant\AuditLogger;
use app\common\tenant\EnterpriseTokenIdentityProvider;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextResolver;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantOwnedResource;
use app\common\tenant\TenantResourceGuard;
use app\common\tenant\TenantTableDefinition;
use app\common\tenant\TenantTableRegistry;
use app\common\identity\IdentityService;
use app\common\identity\LoginAttemptStore;
use app\common\identity\LoginRateLimiter;
use app\common\identity\PasswordHasher;
use app\common\identity\SecurityEventLogger;
use app\common\identity\ThinkAuthTokenStore;
use app\common\identity\ThinkIdentityRepository;
use app\common\migration\MigrationManager;
use think\Config;
use think\Db;

final class T03ScopeFailure extends RuntimeException
{
}

function t03ScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T03ScopeFailure($message);
    }
}

final class T03ScopeAudit implements AuditLogger
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

final class T03ScopeLoginAttemptStore implements LoginAttemptStore
{
    public function reserveAttempt(
        string $key,
        int $now,
        int $maximumFailures,
        int $windowSeconds,
        int $lockoutSeconds
    ): bool {
        return true;
    }

    public function delete(string $key): void
    {
    }
}

final class T03ScopeSecurityLogger implements SecurityEventLogger
{
    public function record(string $event, array $context): void
    {
    }
}

final class T03CompliantResource implements TenantOwnedResource
{
    public function tenantResourceName(): string
    {
        return 'resources';
    }
}

final class T03NonCompliantResource
{
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($environment) ? ($environment['database'] ?? []) : [];
$host = (string) (getenv('GEO_DATABASE_HOSTNAME') ?: ($database['hostname'] ?? ''));
$port = (int) (getenv('GEO_DATABASE_HOSTPORT') ?: ($database['hostport'] ?? 0));
$name = (string) (getenv('GEO_DATABASE_DATABASE') ?: ($database['database'] ?? ''));
$username = (string) (getenv('GEO_DATABASE_USERNAME') ?: ($database['username'] ?? ''));
$password = (string) (getenv('GEO_DATABASE_PASSWORD') ?: ($database['password'] ?? ''));
if ($host === '' || $port < 1 || $name === '' || $username === '') {
    fwrite(STDERR, "FAIL: T03 tenant scope database configuration is incomplete\n");
    exit(1);
}

$resources = 't03_scope_resources';
$related = 't03_scope_related';
$prefix = 't03_scope_';
$companies = $prefix . 'companies';
$users = $prefix . 'user';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$failure = null;
$manager = null;
$before = [];
$knownTables = [
    $prefix . 'questions',
    $prefix . 'keywords',
    $prefix . 'company_profiles',
    $prefix . 'audit_logs',
    $prefix . 'geo_auth_throttles',
    $prefix . 'user_token',
    $resources,
    $related,
    $users,
    $companies,
    $prefix . 'geo_async_jobs',
    $prefix . 'geo_migrations',
];

try {
    $before = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($knownTables as $table) {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $check->execute(['table' => $table]);
        if ((int) $check->fetchColumn() > 0) {
            throw new T03ScopeFailure('T03 tenant scope fixture namespace is not empty');
        }
    }

    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    t03ScopeAssert(
        count($manager->migrateThrough('202608010005_create_keywords_and_questions')) === 5,
        'T01 through T05 fixture migrations must apply'
    );

    $pdo->exec(
        'CREATE TABLE `' . $related . '` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`company_id` BIGINT UNSIGNED NOT NULL,'
        . '`label` VARCHAR(100) NOT NULL,'
        . 'PRIMARY KEY (`id`),'
        . 'KEY `idx_company_label` (`company_id`,`label`),'
        . 'FOREIGN KEY (`company_id`) REFERENCES `' . $companies . '` (`id`)'
        . ' ON DELETE RESTRICT ON UPDATE RESTRICT'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE `' . $resources . '` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`company_id` BIGINT UNSIGNED NOT NULL,'
        . '`related_id` BIGINT UNSIGNED NULL,'
        . '`name` VARCHAR(100) NOT NULL,'
        . '`score` INT NOT NULL DEFAULT 0,'
        . 'PRIMARY KEY (`id`),'
        . 'UNIQUE KEY `uq_company_name` (`company_id`,`name`),'
        . 'KEY `idx_company_score` (`company_id`,`score`),'
        . 'FOREIGN KEY (`company_id`) REFERENCES `' . $companies . '` (`id`)'
        . ' ON DELETE RESTRICT ON UPDATE RESTRICT'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $now = time();
    $insertCompany = $pdo->prepare(
        'INSERT INTO `' . $companies . '` (`name`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:name,:status,:created_at,:updated_at)'
    );
    $insertCompany->execute([
        'name' => 'T03 Fixture A',
        'status' => 'normal',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $companyAId = (int) $pdo->lastInsertId();
    $insertCompany->execute([
        'name' => 'T03 Fixture B',
        'status' => 'normal',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $companyBId = (int) $pdo->lastInsertId();

    $insertUser = $pdo->prepare(
        'INSERT INTO `' . $users . '`'
        . ' (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:company_id,:username,:password_hash,:role,:status,:created_at,:updated_at)'
    );
    $insertUser->execute([
        'company_id' => $companyAId,
        'username' => 't03-owner-a',
        'password_hash' => password_hash('T03-A-Password-Only', PASSWORD_DEFAULT),
        'role' => 'company_owner',
        'status' => 'normal',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $userAId = (int) $pdo->lastInsertId();
    $insertUser->execute([
        'company_id' => $companyBId,
        'username' => 't03-owner-b',
        'password_hash' => password_hash('T03-B-Password-Only', PASSWORD_DEFAULT),
        'role' => 'company_owner',
        'status' => 'normal',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $userBId = (int) $pdo->lastInsertId();

    putenv('GEO_DATABASE_TYPE=mysql');
    putenv('GEO_DATABASE_HOSTNAME=' . $host);
    putenv('GEO_DATABASE_HOSTPORT=' . (string) $port);
    putenv('GEO_DATABASE_DATABASE=' . $name);
    putenv('GEO_DATABASE_USERNAME=' . $username);
    putenv('GEO_DATABASE_PASSWORD=' . $password);
    putenv('GEO_DATABASE_PREFIX=' . $prefix);
    Config::set('database', require ROOT_PATH . 'application' . DIRECTORY_SEPARATOR . 'database.php');
    Config::set('token', [
        'hashalgo' => 'ripemd160',
        'key' => 'test-only-t03-scope-token-key-20260730',
    ]);
    $tokenStore = new ThinkAuthTokenStore();
    $tokenStore->put('t03-token-a-0000000000000001', $userAId, 3600);
    $tokenStore->put('t03-token-b-0000000000000002', $userBId, 3600);
    $identityService = new IdentityService(
        new ThinkIdentityRepository(),
        $tokenStore,
        new LoginRateLimiter(
            new T03ScopeLoginAttemptStore(),
            'test-only-t03-scope-rate-key-20260730'
        ),
        new T03ScopeSecurityLogger(),
        new PasswordHasher()
    );
    $contextResolver = new TenantContextResolver(
        new EnterpriseTokenIdentityProvider($identityService)
    );
    $contextA = $contextResolver->resolve('t03-token-a-0000000000000001');
    $contextB = $contextResolver->resolve('t03-token-b-0000000000000002');
    t03ScopeAssert($contextA->actorId() === $userAId, 'A context must use the persisted A user');
    t03ScopeAssert($contextB->actorId() === $userBId, 'B context must use the persisted B user');
    t03ScopeAssert($contextA->companyId() === $companyAId, 'A context must use the persisted A company');
    t03ScopeAssert($contextB->companyId() === $companyBId, 'B context must use the persisted B company');

    $registry = new TenantTableRegistry([
        'resources' => new TenantTableDefinition(
            $resources,
            ['related_id', 'name', 'score'],
            ['name'],
            ['score']
        ),
        'related' => new TenantTableDefinition($related, ['label'], ['label']),
    ]);
    $audit = new T03ScopeAudit();
    $gateway = new TenantDataGateway($pdo, $registry, $audit);

    $relatedA = $gateway->insert(
        'related',
        ['company_id' => $companyBId, 'label' => 'A-label'],
        $contextA
    );
    $relatedB = $gateway->insert(
        'related',
        ['company_id' => $companyAId, 'label' => 'B-label'],
        $contextB
    );
    $resourceA1 = $gateway->insert(
        'resources',
        ['company_id' => $companyBId, 'related_id' => $relatedA, 'name' => 'A-one', 'score' => 10],
        $contextA
    );
    $resourceA2 = $gateway->insert(
        'resources',
        ['related_id' => $relatedA, 'name' => 'A-two', 'score' => 20],
        $contextA
    );
    $resourceB = $gateway->insert(
        'resources',
        ['related_id' => $relatedB, 'name' => 'B-one', 'score' => 90],
        $contextB
    );

    t03ScopeAssert(
        (int) $gateway->find('resources', $resourceA1, $contextA)['company_id'] === $companyAId,
        'insert must overwrite untrusted company_id with context tenant'
    );
    t03ScopeAssert(count($gateway->list('resources', $contextA)) === 2, 'A list must contain only A data');
    t03ScopeAssert(count($gateway->list('resources', $contextB)) === 1, 'B list must contain only B data');
    t03ScopeAssert(
        count($gateway->list('resources', $contextA, ['company_id' => $companyBId])) === 2,
        'company_id filter must not switch tenant'
    );

    t03ScopeAssert($gateway->find('resources', $resourceB, $contextA, '127.0.0.1') === null, 'A must not read B');
    t03ScopeAssert($gateway->find('resources', 999999, $contextA, '127.0.0.1') === null, 'missing resource is hidden');
    t03ScopeAssert(
        !$gateway->update('resources', $resourceB, ['name' => 'hacked'], $contextA, '127.0.0.1'),
        'A must not update B'
    );
    t03ScopeAssert(
        !$gateway->delete('resources', $resourceB, $contextA, '127.0.0.1'),
        'A must not delete B'
    );
    t03ScopeAssert(
        !$gateway->updateMany(
            'resources',
            [$resourceA1, $resourceB],
            ['score' => 77],
            $contextA,
            '127.0.0.1'
        ),
        'mixed-tenant batch update must fail atomically'
    );
    t03ScopeAssert(
        (int) $gateway->find('resources', $resourceA1, $contextA)['score'] === 10,
        'failed batch must not partially update A data'
    );
    t03ScopeAssert(
        $gateway->update('resources', $resourceA1, ['score' => 10], $contextA, '127.0.0.1'),
        'idempotent update of an in-tenant resource must succeed'
    );
    t03ScopeAssert(
        !$gateway->deleteMany('resources', [$resourceA2, $resourceB], $contextA, '127.0.0.1'),
        'mixed-tenant batch delete must fail atomically'
    );
    t03ScopeAssert($gateway->find('resources', $resourceA2, $contextA) !== null, 'failed batch must not delete A data');

    $search = $gateway->search('resources', $contextA, 'name', 'A-', 1, 1);
    t03ScopeAssert(count($search['items']) === 1 && $search['total'] === 2, 'search pagination must stay in A');
    t03ScopeAssert($gateway->count('resources', $contextA) === 2, 'count must stay in A');
    t03ScopeAssert($gateway->sum('resources', 'score', $contextA) === 30.0, 'aggregate must stay in A');

    $joined = $gateway->joinList(
        'resources',
        'related',
        'related_id',
        'id',
        $contextA
    );
    t03ScopeAssert(count($joined) === 2, 'association must constrain both tables to A');
    t03ScopeAssert(
        count($gateway->whereRelatedExists(
            'resources',
            'related',
            'related_id',
            'id',
            ['label' => 'A-label'],
            $contextA
        )) === 2,
        'subquery must constrain both tables to A'
    );

    $denials = array_values(array_filter($audit->records, static function (array $record): bool {
        return $record['action'] === 'tenant_access_denied';
    }));
    t03ScopeAssert(count($denials) >= 6, 'denied cross-tenant operations must be audited');
    t03ScopeAssert(
        $denials[0]['detail'] === ['outcome' => 'not_found'],
        'audit must not claim whether a foreign resource existed'
    );

    $pdo->prepare('UPDATE `' . $users . "` SET `status`='disabled' WHERE `id`=:id")
        ->execute(['id' => $userAId]);
    try {
        $contextResolver->resolve('t03-token-a-0000000000000001');
        throw new T03ScopeFailure('disabled persisted user must not resolve tenant context');
    } catch (TenantException $expected) {
    }
    $pdo->prepare('UPDATE `' . $companies . "` SET `status`='disabled' WHERE `id`=:id")
        ->execute(['id' => $companyBId]);
    try {
        $contextResolver->resolve('t03-token-b-0000000000000002');
        throw new T03ScopeFailure('disabled persisted company must not resolve tenant context');
    } catch (TenantException $expected) {
    }

    TenantResourceGuard::assertCompliant(T03CompliantResource::class, $registry);
    try {
        TenantResourceGuard::assertCompliant(T03NonCompliantResource::class, $registry);
        throw new T03ScopeFailure('non-compliant resource must be rejected');
    } catch (TenantException $expected) {
    }

    fwrite(
        STDOUT,
        "PASS: T03 persisted A/B identities, tenant CRUD, batch, search, aggregate, join, subquery, audit, and guard flows\n"
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    Db::clear();
    $pdo->exec('DROP TABLE IF EXISTS `' . $resources . '`');
    $pdo->exec('DROP TABLE IF EXISTS `' . $related . '`');
    if ($manager instanceof MigrationManager) {
        try {
            $manager->rollback(1);
        } catch (Throwable $cleanupException) {
            $failure = $failure ?: $cleanupException;
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($knownTables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $after = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($before !== [] && $after !== $before) {
        $failure = new T03ScopeFailure('T03 A/B fixture cleanup changed the database table list');
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . get_class($failure)
        . ' code=' . (string) $failure->getCode()
        . ' file=' . basename($failure->getFile())
        . ' line=' . (string) $failure->getLine()
        . ' message=' . preg_replace(
            '/(?:password|token|secret|authorization|cookie)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $failure->getMessage()
        )
        . PHP_EOL
    );
    exit(1);
}
