<?php

declare(strict_types=1);

if (getenv('GEO_T04_OFFICIAL_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T04 official migration safety test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T04_OFFICIAL_MIGRATION_TEST_ALLOW_DATABASE') !== '1'
    || getenv('GEO_T04_OFFICIAL_MIGRATION_TEST_PREFIX') !== 'fa_'
) {
    fwrite(STDERR, "FAIL: T04 official migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T04OfficialMigrationFailure extends RuntimeException
{
}

function t04OfficialAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T04OfficialMigrationFailure($message);
    }
}

function t04OfficialSnapshot(PDO $pdo, array $excluded): array
{
    $tables = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $table) {
        $table = (string) $table;
        if (in_array($table, $excluded, true)) {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new T04OfficialMigrationFailure('unsafe table identifier in snapshot');
        }
        $columns = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table ORDER BY ORDINAL_POSITION'
        );
        $columns->execute(['table' => $table]);
        $columnNames = $columns->fetchAll(PDO::FETCH_COLUMN);
        $primary = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME='PRIMARY'"
            . ' ORDER BY SEQ_IN_INDEX'
        );
        $primary->execute(['table' => $table]);
        $orderColumns = $primary->fetchAll(PDO::FETCH_COLUMN);
        $order = $orderColumns !== [] ? $orderColumns : $columnNames;
        $orderSql = $order === [] ? '' : ' ORDER BY ' . implode(',', array_map(static function ($column): string {
            return '`' . $column . '`';
        }, $order));
        $rows = $pdo->query('SELECT * FROM `' . $table . '`' . $orderSql)->fetchAll(PDO::FETCH_ASSOC);
        $snapshot[$table] = [
            'count' => count($rows),
            'hash' => hash(
                'sha256',
                json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            ),
        ];
    }
    return $snapshot;
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
$prefix = (string) $processValue('GEO_DATABASE_PREFIX', $database['prefix'] ?? '');
if ($host === '' || $port < 1 || $name === '' || $username === '' || $prefix !== 'fa_') {
    fwrite(STDERR, "FAIL: T04 official database configuration is incomplete or prefix is not fa_\n");
    exit(1);
}

try {
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
    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    $status = $manager->status();
    foreach ([
        '202607300001_create_geo_async_jobs',
        '202607300002_create_company_identity',
        '202607300003_enforce_tenant_isolation',
    ] as $migration) {
        t04OfficialAssert(($status[$migration] ?? null) === 'applied', 'T01-T03 must already be applied');
    }
    if (($status['202608010005_create_keywords_and_questions'] ?? null) === 'applied') {
        t04OfficialAssert(
            ($status['202607300004_create_company_profiles'] ?? null) === 'applied',
            'T04 must remain applied beneath T05'
        );
        foreach (['fa_company_profiles', 'fa_keywords', 'fa_questions'] as $table) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
            );
            $statement->execute(['table' => $table]);
            t04OfficialAssert((int) $statement->fetchColumn() === 1, 'T04/T05 retained structure must exist');
        }
        fwrite(
            STDOUT,
            "PASS: T04 retained official schema structure beneath T05; isolated replay is covered by tests/t04/migration_test.php\n"
        );
        exit(0);
    }
    $excluded = [$prefix . 'geo_migrations', $prefix . 'company_profiles'];
    $before = t04OfficialSnapshot($pdo, $excluded);

    if (($status['202607300004_create_company_profiles'] ?? null) === 'pending') {
        t04OfficialAssert(
            $manager->migrateThrough('202607300004_create_company_profiles') === ['202607300004_create_company_profiles'],
            'official migrate applies only T04'
        );
    }
    t04OfficialAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'company_profiles`')->fetchColumn() === 0,
        'official T04 profile table must be empty before replay'
    );
    $latestBatch = (int) $pdo->query(
        'SELECT MAX(`batch`) FROM `' . $prefix . 'geo_migrations`'
    )->fetchColumn();
    $latest = $pdo->prepare(
        'SELECT `migration` FROM `' . $prefix . 'geo_migrations`'
        . ' WHERE `batch`=:batch ORDER BY `migration`'
    );
    $latest->execute(['batch' => $latestBatch]);
    t04OfficialAssert(
        $latest->fetchAll(PDO::FETCH_COLUMN) === ['202607300004_create_company_profiles'],
        'latest official migration batch contains only T04'
    );

    t04OfficialAssert(
        $manager->rollback(1) === ['202607300004_create_company_profiles'],
        'official rollback removes only T04'
    );
    t04OfficialAssert(
        t04OfficialSnapshot($pdo, $excluded) === $before,
        'official rollback preserves all T01-T03 and existing business rows'
    );
    t04OfficialAssert(
        $manager->migrateThrough('202607300004_create_company_profiles') === ['202607300004_create_company_profiles'],
        'official T04 re-migrates'
    );
    t04OfficialAssert(
        t04OfficialSnapshot($pdo, $excluded) === $before,
        'official re-migrate preserves all pre-existing rows'
    );
    t04OfficialAssert(
        ($manager->status()['202607300004_create_company_profiles'] ?? null) === 'applied',
        'official T04 finishes applied'
    );

    fwrite(
        STDOUT,
        'PASS: T04 official fa_ migrate/rollback/re-migrate preserved '
        . count($before) . " non-T04 tables\n"
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'FAIL: ' . get_class($exception)
        . ' file=' . basename($exception->getFile())
        . ' line=' . (string) $exception->getLine()
        . ' message=' . preg_replace(
            '/(?:password|token|secret|authorization|cookie|key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $exception->getMessage()
        )
        . PHP_EOL
    );
    exit(1);
}
