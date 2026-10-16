<?php

declare(strict_types=1);

if (getenv('GEO_T04_PROFILE_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T04 company profile service test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T04_PROFILE_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T04_PROFILE_TEST_PREFIX') !== 't04_profile_'
) {
    fwrite(STDERR, "FAIL: T04 profile service safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\company\CompanyContext;
use app\common\company\CompanyException;
use app\common\company\CompanyProfileResource;
use app\common\company\CompanyProfileService;
use app\common\company\OpenSslContactCipher;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantCompanyGateway;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantResourceCatalog;
use app\common\tenant\TenantResourceGuard;

final class T04ProfileFailure extends RuntimeException
{
}

function t04ProfileAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T04ProfileFailure($message);
    }
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($environment) ? ($environment['database'] ?? []) : [];
$processValue = static function (string $key, $fallback) {
    $value = getenv($key);
    return $value !== false ? $value : $fallback;
};
$host = (string) $processValue('GEO_DATABASE_HOSTNAME', $database['hostname'] ?? '');
$port = (int) $processValue('GEO_DATABASE_HOSTPORT', $database['hostport'] ?? 0);
$name = (string) $processValue('GEO_DATABASE_DATABASE', $database['database'] ?? '');
$username = (string) $processValue('GEO_DATABASE_USERNAME', $database['username'] ?? '');
$password = getenv('GEO_T04_DATABASE_PASSWORD_IS_EMPTY') === '1'
    ? ''
    : (string) $processValue('GEO_DATABASE_PASSWORD', $database['password'] ?? '');
if ($host === '' || $port < 1 || $name === '' || $username === '') {
    fwrite(STDERR, "FAIL: T04 profile service database configuration is incomplete\n");
    exit(1);
}

$prefix = 't04_profile_';
$knownTables = [
    $prefix . 'questions',
    $prefix . 'keywords',
    $prefix . 'company_profiles',
    $prefix . 'audit_logs',
    $prefix . 'geo_auth_throttles',
    $prefix . 'user_token',
    $prefix . 'user',
    $prefix . 'companies',
    $prefix . 'geo_async_jobs',
    $prefix . 'geo_migrations',
];
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
$before = [];

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
        t04ProfileAssert((int) $check->fetchColumn() === 0, 'T04 profile fixture namespace must be empty');
    }

    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    t04ProfileAssert(
        count($manager->migrateThrough('202608010005_create_keywords_and_questions')) === 5,
        'T01-T05 fixture migrations must apply'
    );

    $now = time();
    $insertCompany = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:name,:status,:created_at,:updated_at)'
    );
    $insertCompany->execute([
        'name' => '企业A',
        'status' => 'normal',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $companyAId = (int) $pdo->lastInsertId();
    $insertCompany->execute([
        'name' => '企业B',
        'status' => 'normal',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $companyBId = (int) $pdo->lastInsertId();
    $contextA = TenantContext::enterprise(101, $companyAId, 't04_test');
    $contextB = TenantContext::enterprise(202, $companyBId, 't04_test');

    $lockPdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->beginTransaction();
    $lockingGateway = new TenantCompanyGateway($pdo, $prefix);
    t04ProfileAssert(
        $lockingGateway->findForUpdate($contextA) !== null,
        'trusted company row can be locked for profile save'
    );
    $lockPdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    $competingSaveWasSerialized = false;
    $lockPdo->beginTransaction();
    try {
        (new TenantCompanyGateway($lockPdo, $prefix))->findForUpdate($contextA);
    } catch (PDOException $expectedLockTimeout) {
        $driverCode = (int) ($expectedLockTimeout->errorInfo[1] ?? 0);
        $competingSaveWasSerialized = $expectedLockTimeout->getCode() === 'HY000'
            && in_array($driverCode, [1205, 1213], true);
    } finally {
        if ($lockPdo->inTransaction()) {
            $lockPdo->rollBack();
        }
        $pdo->rollBack();
    }
    t04ProfileAssert(
        $competingSaveWasSerialized,
        'two connections cannot concurrently enter first-save critical section for one company'
    );

    $audit = new PdoAuditLogger($pdo, $prefix);
    $registry = TenantResourceCatalog::registry($prefix);
    TenantResourceGuard::assertCompliant(CompanyProfileResource::class, $registry);
    $gateway = new TenantDataGateway($pdo, $registry, $audit);
    $service = new CompanyProfileService(
        $pdo,
        $gateway,
        new TenantCompanyGateway($pdo, $prefix),
        new OpenSslContactCipher('base64:' . base64_encode(random_bytes(32))),
        $audit,
        null,
        static function (): int {
            return 1785340800;
        }
    );

    $emptyA = $service->get($contextA);
    t04ProfileAssert($emptyA['company_name'] === '企业A', 'current tenant reads its company name');
    t04ProfileAssert($emptyA['brand'] === null, 'empty profile returns stable null fields');
    t04ProfileAssert($emptyA['products_services'] === [], 'empty profile returns stable product array');
    t04ProfileAssert($emptyA['profile_completeness'] === 13, 'minimal profile completeness is deterministic');

    $savedA = $service->save([
        'company_name' => '企业A更新',
        'brand' => '<img src=x onerror=alert(1)>',
        'industry' => '软件服务',
        'products_services' => ['GEO分析', '内容优化'],
        'website' => 'https://example.test/company',
        'introduction' => '<script>globalThis.t04Xss=true</script>',
        'mobile' => '+8613800138000',
        'email' => 'OWNER@EXAMPLE.TEST',
        'status' => 'disabled',
        'verify_status' => 'verified',
    ], $contextA, '127.0.0.1');
    t04ProfileAssert($savedA['company_name'] === '企业A更新', 'company name is updated');
    t04ProfileAssert($savedA['contacts']['email'] === 'owner@example.test', 'authorized contact read is normalized');
    t04ProfileAssert(
        $savedA['introduction'] === '<script>globalThis.t04Xss=true</script>',
        'plain text introduction is preserved for escaped rendering'
    );
    t04ProfileAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'company_profiles`')->fetchColumn() === 1,
        'first save creates one profile'
    );
    $raw = $pdo->query(
        'SELECT `id`,`company_id`,`contacts_ciphertext` FROM `' . $prefix . 'company_profiles` LIMIT 1'
    )->fetch();
    t04ProfileAssert((int) $raw['company_id'] === $companyAId, 'insert binds trusted tenant');
    t04ProfileAssert(strpos((string) $raw['contacts_ciphertext'], '13800138000') === false, 'mobile is encrypted');
    t04ProfileAssert(strpos((string) $raw['contacts_ciphertext'], 'owner@example.test') === false, 'email is encrypted');

    $service->save([
        'company_name' => '企业A更新',
        'brand' => '<img src=x onerror=alert(1)>',
        'industry' => '软件服务',
        'products_services' => ['GEO分析', '内容优化'],
        'website' => 'https://example.test/company',
        'introduction' => '<script>globalThis.t04Xss=true</script>',
        'mobile' => '+8613800138000',
        'email' => 'owner@example.test',
    ], $contextA, '127.0.0.1');
    t04ProfileAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'company_profiles`')->fetchColumn() === 1,
        'repeat save remains one-to-one'
    );

    try {
        $service->save(['company_name' => '越权', 'company_id' => $companyBId], $contextA, '127.0.0.1');
        throw new T04ProfileFailure('body company_id must not switch tenant');
    } catch (CompanyException $expected) {
    }

    $service->save([
        'company_name' => "企业B' OR 1=1 --",
        'brand' => null,
        'industry' => null,
        'products_services' => [],
        'website' => null,
        'introduction' => null,
        'mobile' => null,
        'email' => null,
    ], $contextB, '127.0.0.2');
    $profiles = $pdo->query(
        'SELECT `id`,`company_id` FROM `' . $prefix . 'company_profiles` ORDER BY `id`'
    )->fetchAll();
    t04ProfileAssert(count($profiles) === 2, 'SQL-like text cannot alter profile query scope');
    $profileBId = (int) $profiles[1]['id'];
    t04ProfileAssert(
        $gateway->find('company_profiles', $profileBId, $contextA, '127.0.0.1') === null,
        'A cannot read B profile by ID'
    );
    t04ProfileAssert(
        !$gateway->update(
            'company_profiles',
            $profileBId,
            ['brand' => 'hacked'],
            $contextA,
            '127.0.0.1'
        ),
        'A cannot update B profile by ID'
    );
    t04ProfileAssert($service->get($contextB)['brand'] === null, 'B profile remains unchanged');

    $aiA = $service->context($contextA)->toArray();
    $aiB = $service->context($contextB)->toArray();
    t04ProfileAssert(
        array_keys($aiA) === array_keys(CompanyContext::fromProfile([])->toArray()),
        'AI context has stable fields'
    );
    t04ProfileAssert(!array_key_exists('contacts', $aiA), 'AI context omits contacts');
    t04ProfileAssert($aiA['company_id'] === $companyAId, 'A context keeps internal A tenant ID');
    t04ProfileAssert($aiB['company_id'] === $companyBId, 'B context keeps internal B tenant ID');
    t04ProfileAssert($aiA['company_name'] !== $aiB['company_name'], 'contexts do not cross tenants');

    $auditRows = $pdo->query(
        'SELECT `action`,`company_id`,`detail_json` FROM `' . $prefix . 'audit_logs` ORDER BY `id`'
    )->fetchAll();
    $profileAudits = array_values(array_filter($auditRows, static function (array $row): bool {
        return $row['action'] === 'company_profile_saved';
    }));
    t04ProfileAssert(count($profileAudits) === 2, 'create and B create produce profile audits; no-op repeat does not');
    foreach ($auditRows as $row) {
        $serialized = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        t04ProfileAssert(strpos($serialized, '13800138000') === false, 'audit never stores mobile');
        t04ProfileAssert(stripos($serialized, 'owner@example.test') === false, 'audit never stores email');
    }
    $detail = json_decode((string) $profileAudits[0]['detail_json'], true);
    t04ProfileAssert(($detail['contacts_modified'] ?? null) === true, 'audit records only contact modification flag');
    t04ProfileAssert(!array_key_exists('mobile', $detail), 'audit does not include contact fields');

    fwrite(
        STDOUT,
        "PASS: T04 service CRUD, one-to-one, encryption, audit, A/B isolation, XSS text, SQL input, and context\n"
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
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
        $failure = new T04ProfileFailure('T04 profile fixture cleanup changed the database table list');
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . get_class($failure)
        . ' file=' . basename($failure->getFile())
        . ' line=' . (string) $failure->getLine()
        . ' message=' . preg_replace(
            '/(?:mobile|email|contact|password|token|secret|authorization|cookie|key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $failure->getMessage()
        )
        . PHP_EOL
    );
    exit(1);
}
