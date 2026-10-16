<?php

declare(strict_types=1);

if (getenv('GEO_T03_OFFICIAL_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T03 official migration safety test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T03_OFFICIAL_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_DATABASE_PREFIX') !== 'fa_'
) {
    fwrite(STDERR, "FAIL: T03 official migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T03OfficialFailure extends RuntimeException
{
}

function t03OfficialAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T03OfficialFailure($message);
    }
}

function t03OfficialTables(PDO $pdo): array
{
    return $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
}

function t03OfficialSnapshot(PDO $pdo, array $tables): array
{
    $snapshot = [];
    foreach ($tables as $table) {
        if (preg_match('/^[A-Za-z0-9_]+$/', (string) $table) !== 1) {
            throw new T03OfficialFailure('unexpected table identifier');
        }
        $columns = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table ORDER BY ORDINAL_POSITION'
        );
        $columns->execute(['table' => $table]);
        $columnNames = $columns->fetchAll(PDO::FETCH_COLUMN);
        $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        $encodedRows = [];
        foreach ($rows as $row) {
            ksort($row, SORT_STRING);
            $encodedRows[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        sort($encodedRows, SORT_STRING);
        $snapshot[$table] = [
            'columns' => $columnNames,
            'rows' => hash('sha256', implode("\n", $encodedRows)),
            'count' => count($rows),
        ];
    }
    return $snapshot;
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($environment) ? ($environment['database'] ?? []) : [];
$host = (string) (getenv('GEO_DATABASE_HOSTNAME') ?: ($database['hostname'] ?? ''));
$port = (int) (getenv('GEO_DATABASE_HOSTPORT') ?: ($database['hostport'] ?? 0));
$name = (string) (getenv('GEO_DATABASE_DATABASE') ?: ($database['database'] ?? ''));
$username = (string) (getenv('GEO_DATABASE_USERNAME') ?: ($database['username'] ?? ''));
$password = (string) (getenv('GEO_DATABASE_PASSWORD') ?: ($database['password'] ?? ''));
if ($host === '' || $port < 1 || $name === '' || $username === '') {
    fwrite(STDERR, "FAIL: T03 official migration database configuration is incomplete\n");
    exit(1);
}

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
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', 'fa_');
$t03 = '202607300003_enforce_tenant_isolation';
$status = $manager->status();
t03OfficialAssert(($status['202607300001_create_geo_async_jobs'] ?? '') === 'applied', 'T01 must be applied');
t03OfficialAssert(($status['202607300002_create_company_identity'] ?? '') === 'applied', 'T02 must be applied');
if (($status['202607300004_create_company_profiles'] ?? '') === 'applied') {
    t03OfficialAssert(($status[$t03] ?? '') === 'applied', 'T03 must remain applied beneath T04');
    foreach (['fa_audit_logs', 'fa_company_profiles'] as $table) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $statement->execute(['table' => $table]);
        t03OfficialAssert((int) $statement->fetchColumn() === 1, 'T03/T04 structure must exist');
    }
    $foreignKey = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fa_user'"
        . " AND COLUMN_NAME='company_id' AND REFERENCED_TABLE_NAME='fa_companies'"
    )->fetchColumn();
    t03OfficialAssert((int) $foreignKey === 1, 'T03 user tenant foreign key must remain intact');
    fwrite(
        STDOUT,
        "PASS: T03 retained official schema structure check beneath T04; "
        . "full replay evidence is produced separately by tests/t03/migration_test.php\n"
    );
    exit(0);
}

if (($status[$t03] ?? '') === 'applied') {
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM `fa_audit_logs`')->fetchColumn();
    t03OfficialAssert($auditCount === 0, 'official T03 replay requires an empty audit table');
    t03OfficialAssert($manager->rollback(1) === [$t03], 'pre-test rollback must remove only T03');
}

$originalTables = array_values(array_filter(t03OfficialTables($pdo), static function (string $table): bool {
    return $table !== 'fa_geo_migrations' && $table !== 'fa_audit_logs';
}));
$before = t03OfficialSnapshot($pdo, $originalTables);

t03OfficialAssert($manager->migrateThrough($t03) === [$t03], 'official T03 migrate must apply exactly T03');
t03OfficialAssert(
    t03OfficialSnapshot($pdo, $originalTables) === $before,
    'official T03 migrate must preserve every existing table row'
);
t03OfficialAssert($manager->rollback(1) === [$t03], 'official rollback must remove only T03');
t03OfficialAssert(
    t03OfficialSnapshot($pdo, $originalTables) === $before,
    'official T03 rollback must preserve every existing table row'
);
t03OfficialAssert($manager->migrateThrough($t03) === [$t03], 'official T03 re-migrate must apply exactly T03');
t03OfficialAssert(
    t03OfficialSnapshot($pdo, $originalTables) === $before,
    'official T03 re-migrate must preserve every existing table row'
);

$finalStatus = $manager->status();
t03OfficialAssert(($finalStatus[$t03] ?? '') === 'applied', 'T03 must finish applied');
fwrite(STDOUT, "PASS: T03 official migrate/rollback/re-migrate preserved all T01/T02 data\n");
