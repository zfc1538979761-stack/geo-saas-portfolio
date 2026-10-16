<?php

declare(strict_types=1);

if (getenv('GEO_T03_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T03 migration test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T03_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T03_MIGRATION_TEST_PREFIX') !== 't03_acceptance_'
) {
    fwrite(STDERR, "FAIL: T03 migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;

final class T03MigrationFailure extends RuntimeException
{
}

function t03MigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T03MigrationFailure($message);
    }
}

function t03MigrationTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
    );
    $statement->execute(['table' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($environment) ? ($environment['database'] ?? []) : [];
$host = (string) (getenv('GEO_DATABASE_HOSTNAME') ?: ($database['hostname'] ?? ''));
$port = (int) (getenv('GEO_DATABASE_HOSTPORT') ?: ($database['hostport'] ?? 0));
$name = (string) (getenv('GEO_DATABASE_DATABASE') ?: ($database['database'] ?? ''));
$username = (string) (getenv('GEO_DATABASE_USERNAME') ?: ($database['username'] ?? ''));
$password = (string) (getenv('GEO_DATABASE_PASSWORD') ?: ($database['password'] ?? ''));
if ($host === '' || $port < 1 || $name === '' || $username === '') {
    fwrite(STDERR, "FAIL: T03 migration database configuration is incomplete\n");
    exit(1);
}

$prefix = 't03_acceptance_';
$migrationSource = ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations';
$t03Migration = $migrationSource . DIRECTORY_SEPARATOR . '202607300003_enforce_tenant_isolation.php';
if (!is_file($t03Migration)) {
    fwrite(STDERR, "FAIL: T03 migration file is missing\n");
    exit(1);
}

$migrationPath = ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR . 't03_migration_test_' . getmypid();
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
$knownTables = [
    $prefix . 'audit_logs',
    $prefix . 'geo_auth_throttles',
    $prefix . 'user_token',
    $prefix . 'user',
    $prefix . 'companies',
    $prefix . 'geo_async_jobs',
    $prefix . 'geo_migrations',
];
$failure = null;

try {
    foreach ($knownTables as $table) {
        if (t03MigrationTableExists($pdo, $table)) {
            throw new T03MigrationFailure('T03 migration namespace is not empty');
        }
    }
    if (!mkdir($migrationPath, 0700) && !is_dir($migrationPath)) {
        throw new T03MigrationFailure('T03 temporary migration directory could not be created');
    }
    foreach ([
        '202607300001_create_geo_async_jobs.php',
        '202607300002_create_company_identity.php',
    ] as $migration) {
        if (!copy($migrationSource . DIRECTORY_SEPARATOR . $migration, $migrationPath . DIRECTORY_SEPARATOR . $migration)) {
            throw new T03MigrationFailure('T03 migration fixture copy failed');
        }
    }

    $manager = new MigrationManager($pdo, $migrationPath, $prefix);
    t03MigrationAssert(count($manager->migrate()) === 2, 'T01 and T02 must migrate as the first batch');
    t03MigrationAssert(
        copy($t03Migration, $migrationPath . DIRECTORY_SEPARATOR . basename($t03Migration)),
        'T03 migration fixture copy must succeed'
    );
    $manager = new MigrationManager($pdo, $migrationPath, $prefix);
    t03MigrationAssert(
        $manager->migrate() === ['202607300003_enforce_tenant_isolation'],
        'T03 must migrate as its own batch'
    );

    $auditTable = $prefix . 'audit_logs';
    $userTable = $prefix . 'user';
    $companyTable = $prefix . 'companies';
    t03MigrationAssert(t03MigrationTableExists($pdo, $auditTable), 'audit_logs must be created');

    $columns = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
    );
    $columns->execute(['table' => $auditTable]);
    $actualColumns = $columns->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'id',
        'actor_id',
        'actor_type',
        'company_id',
        'action',
        'target_type',
        'target_id',
        'ip_address',
        'detail_json',
        'created_at',
    ] as $column) {
        t03MigrationAssert(in_array($column, $actualColumns, true), 'audit_logs missing required column');
    }

    $indexes = $pdo->prepare(
        'SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) columns_list'
        . ' FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        . ' GROUP BY INDEX_NAME'
    );
    $indexes->execute(['table' => $auditTable]);
    $indexMap = [];
    foreach ($indexes->fetchAll() as $index) {
        $indexMap[$index['INDEX_NAME']] = $index['columns_list'];
    }
    t03MigrationAssert(
        in_array('company_id,created_at', $indexMap, true),
        'audit_logs needs company and created index'
    );
    t03MigrationAssert(
        in_array('company_id,action,created_at', $indexMap, true),
        'audit_logs needs company, action and created index'
    );
    t03MigrationAssert(
        in_array('actor_id,created_at', $indexMap, true),
        'audit_logs needs actor and created index'
    );

    $foreignKey = $pdo->prepare(
        'SELECT k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE'
        . ' FROM information_schema.KEY_COLUMN_USAGE k'
        . ' INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
        . ' ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME'
        . ' WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=:table'
        . " AND k.COLUMN_NAME='company_id' AND k.REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $foreignKey->execute(['table' => $userTable]);
    $userForeignKey = $foreignKey->fetch();
    t03MigrationAssert(is_array($userForeignKey), 'user.company_id foreign key must exist');
    t03MigrationAssert(
        $userForeignKey['REFERENCED_TABLE_NAME'] === $companyTable
        && $userForeignKey['REFERENCED_COLUMN_NAME'] === 'id'
        && in_array($userForeignKey['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true),
        'user.company_id foreign key must restrict company deletion'
    );

    $now = time();
    $insertCompany = $pdo->prepare(
        'INSERT INTO `' . $companyTable . '` (`name`,`status`,`createtime`,`updatetime`)'
        . " VALUES (:name,'normal',:created_at,:updated_at)"
    );
    $insertCompany->execute(['name' => 'Fixture A', 'created_at' => $now, 'updated_at' => $now]);
    $companyId = (int) $pdo->lastInsertId();
    $insertUser = $pdo->prepare(
        'INSERT INTO `' . $userTable . '`'
        . ' (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`)'
        . " VALUES (:company_id,'fixture-a',NULL,'company_owner','normal',:created_at,:updated_at)"
    );
    $insertUser->execute([
        'company_id' => $companyId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $userId = (int) $pdo->lastInsertId();

    try {
        $pdo->exec('DELETE FROM `' . $companyTable . '` WHERE `id`=' . $companyId);
        throw new T03MigrationFailure('company deletion with active user must be restricted');
    } catch (PDOException $expected) {
    }

    $logger = new PdoAuditLogger($pdo, $prefix);
    $logger->record(
        'tenant_access_denied',
        TenantContext::enterprise($userId, $companyId),
        'fixture',
        '99',
        '127.0.0.1',
        ['password' => 'must-not-persist', 'token' => 'must-not-persist', 'reason' => 'not_found']
    );
    $audit = $pdo->query(
        'SELECT `actor_id`,`company_id`,`action`,`detail_json` FROM `' . $auditTable . '` LIMIT 1'
    )->fetch();
    t03MigrationAssert((int) $audit['actor_id'] === $userId, 'audit actor must be stored');
    t03MigrationAssert((int) $audit['company_id'] === $companyId, 'audit company must be stored');
    t03MigrationAssert(
        strpos((string) $audit['detail_json'], 'must-not-persist') === false,
        'audit detail must redact secrets'
    );

    try {
        $manager->rollback(1);
        throw new T03MigrationFailure('rollback with audit data must be refused');
    } catch (RuntimeException $expected) {
        t03MigrationAssert(
            t03MigrationTableExists($pdo, $auditTable),
            'refused rollback must preserve audit table'
        );
    }

    $pdo->exec('DELETE FROM `' . $auditTable . '`');
    $pdo->exec('DELETE FROM `' . $userTable . '` WHERE `id`=' . $userId);
    $pdo->exec('DELETE FROM `' . $companyTable . '` WHERE `id`=' . $companyId);

    t03MigrationAssert(
        $manager->rollback(1) === ['202607300003_enforce_tenant_isolation'],
        'latest rollback must remove only T03'
    );
    t03MigrationAssert(!t03MigrationTableExists($pdo, $auditTable), 'T03 rollback must drop audit_logs');
    t03MigrationAssert(t03MigrationTableExists($pdo, $userTable), 'T03 rollback must preserve T02 user');
    t03MigrationAssert(t03MigrationTableExists($pdo, $companyTable), 'T03 rollback must preserve T02 companies');
    t03MigrationAssert(
        $manager->migrate() === ['202607300003_enforce_tenant_isolation'],
        'T03 must re-migrate after rollback'
    );

    t03MigrationAssert(
        $manager->rollback(2) === [
            '202607300003_enforce_tenant_isolation',
            '202607300002_create_company_identity',
            '202607300001_create_geo_async_jobs',
        ],
        'test cleanup rollback order must include T03, T02 and T01'
    );
    fwrite(STDOUT, "PASS: T03 empty migrate, FK/index audit, rollback, and re-migrate flows\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($knownTables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    if (is_dir($migrationPath)) {
        foreach (glob($migrationPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
            unlink($path);
        }
        rmdir($migrationPath);
    }
}

if ($failure !== null) {
    $message = preg_replace(
        '/(?:password|token|secret|authorization|cookie)\s*[=:]\s*[^\s,;]+/i',
        '$1=[REDACTED]',
        $failure->getMessage()
    );
    fwrite(
        STDERR,
        'FAIL: ' . get_class($failure) . ' code=' . (string) $failure->getCode()
        . ' message=' . $message . PHP_EOL
    );
    exit(1);
}
