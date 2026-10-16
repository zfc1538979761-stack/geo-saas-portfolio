<?php

declare(strict_types=1);

if (getenv('GEO_T04_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T04 isolated migration test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T04_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T04_MIGRATION_TEST_PREFIX') !== 't04_migration_'
) {
    fwrite(STDERR, "FAIL: T04 migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T04MigrationFailure extends RuntimeException
{
}

function t04MigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T04MigrationFailure($message);
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
    fwrite(STDERR, "FAIL: T04 migration database configuration is incomplete\n");
    exit(1);
}

$prefix = 't04_migration_';
$tables = [
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
$before = [];
$failure = null;

try {
    $before = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        t04MigrationAssert(!in_array($table, $before, true), 'T04 migration namespace must be empty');
    }
    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    $applied = $manager->migrateThrough('202608010005_create_keywords_and_questions');
    t04MigrationAssert(count($applied) === 5, 'empty namespace applies T01 through T05 in order');
    t04MigrationAssert(
        $applied[3] === '202607300004_create_company_profiles',
        'T04 remains the fourth migration beneath T05'
    );

    $profileTable = $prefix . 'company_profiles';
    $columns = $pdo->query(
        'SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE FROM information_schema.COLUMNS'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $profileTable . "'"
        . ' ORDER BY ORDINAL_POSITION'
    )->fetchAll();
    $columnNames = array_column($columns, 'COLUMN_NAME');
    foreach ([
        'id',
        'company_id',
        'brand',
        'industry',
        'products_json',
        'website',
        'introduction',
        'contacts_ciphertext',
        'createtime',
        'updatetime',
    ] as $column) {
        t04MigrationAssert(in_array($column, $columnNames, true), 'profile column exists: ' . $column);
    }
    $products = array_values(array_filter($columns, static function (array $column): bool {
        return $column['COLUMN_NAME'] === 'products_json';
    }));
    t04MigrationAssert(($products[0]['DATA_TYPE'] ?? '') === 'json', 'products use native JSON storage');
    $companyColumn = array_values(array_filter($columns, static function (array $column): bool {
        return $column['COLUMN_NAME'] === 'company_id';
    }));
    t04MigrationAssert(($companyColumn[0]['IS_NULLABLE'] ?? '') === 'NO', 'company_id is required');

    $unique = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.STATISTICS'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $profileTable . "'"
        . " AND COLUMN_NAME='company_id' AND SEQ_IN_INDEX=1 AND NON_UNIQUE=0"
    )->fetchColumn();
    t04MigrationAssert((int) $unique === 1, 'company_id has one-to-one unique constraint');
    $foreignKey = $pdo->query(
        'SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $profileTable . "'"
        . " AND COLUMN_NAME='company_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
    )->fetchColumn();
    t04MigrationAssert($foreignKey === $prefix . 'companies', 'company_id references companies.id');

    $now = time();
    $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:name,:status,:created,:updated)'
    )->execute(['name' => '迁移企业', 'status' => 'normal', 'created' => $now, 'updated' => $now]);
    $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO `' . $profileTable . '`'
        . ' (`company_id`,`products_json`,`createtime`,`updatetime`)'
        . " VALUES (:company_id,'[]',:created,:updated)"
    )->execute(['company_id' => $companyId, 'created' => $now, 'updated' => $now]);

    // A fresh installation applies all four in one batch. Production T04 is
    // deployed after T01-T03, so model that real repository state before
    // asserting the latest-batch rollback boundary.
    $pdo->exec('UPDATE `' . $prefix . 'geo_migrations` SET `batch`=1');
    $pdo->exec(
        'UPDATE `' . $prefix . 'geo_migrations` SET `batch`=2'
        . " WHERE `migration`='202607300004_create_company_profiles'"
    );
    $rolledBack = $manager->rollback(1);
    t04MigrationAssert(
        $rolledBack === ['202607300004_create_company_profiles'],
        'rollback removes only T04'
    );
    t04MigrationAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $profileTable . "'"
        )->fetchColumn() === 0,
        'T04 table is removed'
    );
    t04MigrationAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM `' . $prefix . 'companies` WHERE `id`=' . $companyId
        )->fetchColumn() === 1,
        'rollback preserves T02 company data'
    );
    t04MigrationAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $prefix . "audit_logs'"
        )->fetchColumn() === 1,
        'rollback preserves T03 audit structure'
    );

    $reapplied = $manager->migrateThrough('202608010005_create_keywords_and_questions');
    t04MigrationAssert(
        $reapplied === ['202607300004_create_company_profiles'],
        'T04 re-migrates after rollback'
    );
    t04MigrationAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $profileTable . "'"
        )->fetchColumn() === 1,
        'T04 table exists after re-migration'
    );

    fwrite(STDOUT, "PASS: T04 empty migrate, schema, rollback-only-T04, preserve T01-T03, and re-migrate\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $after = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($before !== [] && $after !== $before) {
        $failure = new T04MigrationFailure('T04 migration cleanup changed database table list');
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . get_class($failure)
        . ' file=' . basename($failure->getFile())
        . ' line=' . (string) $failure->getLine()
        . ' message=' . preg_replace(
            '/(?:password|token|secret|authorization|cookie|key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $failure->getMessage()
        )
        . PHP_EOL
    );
    exit(1);
}
