<?php

declare(strict_types=1);

if (getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T05 official migration safety test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_ALLOW_DATABASE') !== '1'
    || getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_PREFIX') !== 'fa_'
    || getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_DATABASE') === false
    || getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_HOST') === false
    || getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_PORT') === false
) {
    fwrite(STDERR, "FAIL: T05 official migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T05OfficialFailure extends RuntimeException
{
}

function t05OfficialAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T05OfficialFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t05OfficialSnapshot(PDO $pdo, array $excluded): array
{
    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
        . " AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $table) {
        $table = (string) $table;
        if (in_array($table, $excluded, true)) {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new T05OfficialFailure('unsafe official table identifier');
        }
        $columns = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table ORDER BY ORDINAL_POSITION'
        );
        $columns->execute(['table' => $table]);
        $columnNames = $columns->fetchAll(PDO::FETCH_COLUMN);
        $primary = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX"
        );
        $primary->execute(['table' => $table]);
        $order = $primary->fetchAll(PDO::FETCH_COLUMN);
        $order = $order === [] ? $columnNames : $order;
        $orderSql = $order === [] ? '' : ' ORDER BY ' . implode(',', array_map(static function (string $column): string {
            return '`' . $column . '`';
        }, $order));
        $rows = $pdo->query('SELECT * FROM `' . $table . '`' . $orderSql)->fetchAll(PDO::FETCH_ASSOC);
        $snapshot[$table] = [
            'count' => count($rows),
            'hash' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }
    return $snapshot;
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($environment) ? ($environment['database'] ?? []) : [];
$value = static function (string $key, $fallback) {
    $current = getenv($key);
    return $current === false ? $fallback : $current;
};
$host = (string) $value('GEO_DATABASE_HOSTNAME', $database['hostname'] ?? '');
$port = (int) $value('GEO_DATABASE_HOSTPORT', $database['hostport'] ?? 0);
$name = (string) $value('GEO_DATABASE_DATABASE', $database['database'] ?? '');
$user = (string) $value('GEO_DATABASE_USERNAME', $database['username'] ?? '');
$password = (string) $value('GEO_DATABASE_PASSWORD', $database['password'] ?? '');
$prefix = (string) $value('GEO_DATABASE_PREFIX', $database['prefix'] ?? '');
$expectedName = (string) getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_DATABASE');
$expectedHost = (string) getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_HOST');
$expectedPort = (int) getenv('GEO_T05_OFFICIAL_MIGRATION_TEST_PORT');
if (
    $host === '' || $port < 1 || $name === '' || $user === '' || $prefix !== 'fa_'
    || $name !== $expectedName || $host !== $expectedHost || $port !== $expectedPort
    || preg_match('/_(?:test|regression)(?:_[A-Za-z0-9]+)?$/D', $name) !== 1
    || $port === 3306
    || getenv('GEO_DATABASE_DATABASE') === false
    || getenv('GEO_DATABASE_HOSTNAME') === false
    || getenv('GEO_DATABASE_HOSTPORT') === false
) {
    fwrite(STDERR, "FAIL: T05 official database is not an explicit isolated test target\n");
    exit(1);
}

try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name), $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    $status = $manager->status();
    foreach ([
        '202607300001_create_geo_async_jobs',
        '202607300002_create_company_identity',
        '202607300003_enforce_tenant_isolation',
        '202607300004_create_company_profiles',
    ] as $migration) {
        t05OfficialAssert(($status[$migration] ?? null) === 'applied', 'T01-T04 remain applied before T05 replay');
    }
    if (($status['202608010005_create_keywords_and_questions'] ?? null) === 'applied') {
        foreach (['fa_keywords', 'fa_questions'] as $table) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
            );
            $statement->execute(['table' => $table]);
            t05OfficialAssert((int) $statement->fetchColumn() === 1, 'T05 retained table exists beneath T06');
        }
        fwrite(STDOUT, "RESULT: T05 retained official schema structure beneath T06; isolated replay remains covered by tests/t05/migration_test.php\n");
        exit(0);
    }
    $excluded = ['fa_geo_migrations', 'fa_keywords', 'fa_questions'];
    $before = t05OfficialSnapshot($pdo, $excluded);
    $t05 = '202608010005_create_keywords_and_questions';
    if (($status[$t05] ?? null) === 'pending') {
        t05OfficialAssert($manager->migrateThrough($t05) === [$t05], 'official migrate applies only T05');
    }
    t05OfficialAssert((int) $pdo->query('SELECT COUNT(*) FROM `fa_keywords`')->fetchColumn() === 0, 'official keywords table is empty before replay');
    t05OfficialAssert((int) $pdo->query('SELECT COUNT(*) FROM `fa_questions`')->fetchColumn() === 0, 'official questions table is empty before replay');
    $latestBatch = (int) $pdo->query('SELECT MAX(`batch`) FROM `fa_geo_migrations`')->fetchColumn();
    $latest = $pdo->prepare('SELECT `migration` FROM `fa_geo_migrations` WHERE `batch`=:batch ORDER BY `migration`');
    $latest->execute(['batch' => $latestBatch]);
    t05OfficialAssert($latest->fetchAll(PDO::FETCH_COLUMN) === [$t05], 'latest official batch contains only T05');
    t05OfficialAssert($manager->rollback(1) === [$t05], 'official rollback removes only T05');
    t05OfficialAssert(t05OfficialSnapshot($pdo, $excluded) === $before, 'T05 rollback preserves every T01-T04 row');
    t05OfficialAssert($manager->migrateThrough($t05) === [$t05], 'official T05 re-migrates');
    t05OfficialAssert(t05OfficialSnapshot($pdo, $excluded) === $before, 'T05 re-migrate preserves every T01-T04 row');
    t05OfficialAssert(($manager->status()[$t05] ?? null) === 'applied', 'official T05 finishes applied');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . get_class($exception) . ' file=' . basename($exception->getFile())
        . ' line=' . $exception->getLine() . ' message=' . preg_replace(
            '/(?:password|token|secret|authorization|cookie|key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $exception->getMessage()
        ) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T05 official fa_ migrate, rollback-only-T05, preserve T01-T04, and re-migrate passed\n");
