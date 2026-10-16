<?php

declare(strict_types=1);

if (getenv('GEO_T02_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T02 isolated migration test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T02_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
) {
    fwrite(STDERR, "FAIL: T02 migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

function t02MigrationFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$prefix = (string) getenv('GEO_T02_MIGRATION_TEST_PREFIX');
if ($prefix !== 't02_acceptance_') {
    t02MigrationFail('T02 migration prefix safety gate rejected the prefix');
}

$environmentFile = ROOT_PATH . '.env';
$environment = is_file($environmentFile) ? parse_ini_file($environmentFile, true, INI_SCANNER_RAW) : false;
$databaseConfig = is_array($environment) ? ($environment['database'] ?? []) : [];
$database = (string) (getenv('GEO_DATABASE_DATABASE') ?: ($databaseConfig['database'] ?? ''));
$host = (string) (getenv('GEO_DATABASE_HOSTNAME') ?: ($databaseConfig['hostname'] ?? ''));
$port = (int) (getenv('GEO_DATABASE_HOSTPORT') ?: ($databaseConfig['hostport'] ?? 0));
$username = (string) (getenv('GEO_DATABASE_USERNAME') ?: ($databaseConfig['username'] ?? ''));
$password = (string) (getenv('GEO_DATABASE_PASSWORD') ?: ($databaseConfig['password'] ?? ''));
if ($database === '' || $host === '' || $port < 1 || $username === '') {
    t02MigrationFail('T02 migration database configuration is incomplete');
}

$pdo = null;
$before = [];
$failure = null;
$migrationTables = [
    't02_acceptance_audit_logs',
    't02_acceptance_companies',
    't02_acceptance_company_profiles',
    't02_acceptance_geo_async_jobs',
    't02_acceptance_geo_auth_throttles',
    't02_acceptance_geo_migrations',
    't02_acceptance_keywords',
    't02_acceptance_questions',
    't02_acceptance_user',
    't02_acceptance_user_token',
];
$dropOrder = [
    't02_acceptance_questions',
    't02_acceptance_keywords',
    't02_acceptance_company_profiles',
    't02_acceptance_audit_logs',
    't02_acceptance_geo_auth_throttles',
    't02_acceptance_user_token',
    't02_acceptance_user',
    't02_acceptance_companies',
    't02_acceptance_geo_async_jobs',
    't02_acceptance_geo_migrations',
];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $before = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    $staleTables = array_values(array_filter($before, static function ($table) use ($prefix): bool {
        return strpos((string) $table, $prefix) === 0;
    }));
    if ($staleTables !== []) {
        $unexpectedStaleTables = array_diff($staleTables, $migrationTables);
        if (
            getenv('GEO_T02_MIGRATION_TEST_CLEAN_STALE') !== '1'
            || $unexpectedStaleTables !== []
        ) {
            throw new RuntimeException('T02 migration namespace is not empty');
        }
        foreach ($dropOrder as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $before = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );

    $pdo->exec(
        'CREATE TABLE `t02_acceptance_user` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`group_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
        . '`company_id` BIGINT UNSIGNED NULL,'
        . 'PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec('INSERT INTO `t02_acceptance_user` (`group_id`,`company_id`) VALUES (1,987654)');
    try {
        $manager->migrateThrough('202608010005_create_keywords_and_questions');
        throw new RuntimeException('T02 migration accepted a pre-existing identity column');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'T02 migration accepted a pre-existing identity column') {
            throw $exception;
        }
    }
    if ((int) $pdo->query(
        'SELECT `company_id` FROM `t02_acceptance_user` WHERE `id`=1'
    )->fetchColumn() !== 987654) {
        throw new RuntimeException('T02 collision guard changed pre-existing identity data');
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES"
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='t02_acceptance_companies'"
    )->fetchColumn() !== 0) {
        throw new RuntimeException('T02 collision guard left partial identity tables');
    }
    $pdo->exec('DROP TABLE `t02_acceptance_user`');
    if ($manager->rollback(1) !== ['202607300001_create_geo_async_jobs']) {
        throw new RuntimeException('T02 collision guard cleanup did not roll back the isolated T01 batch');
    }

    $manager->migrateThrough('202608010005_create_keywords_and_questions');

    $created = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 't02\\_acceptance\\_%'"
        . ' ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($created !== $migrationTables) {
        throw new RuntimeException('Current migration set created unexpected T02 acceptance tables');
    }

    $userColumns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='t02_acceptance_user'"
        . ' ORDER BY ORDINAL_POSITION'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (
        ['id', 'company_id', 'username', 'password_hash', 'role', 'status', 'createtime', 'updatetime']
        as $requiredColumn
    ) {
        if (!in_array($requiredColumn, $userColumns, true)) {
            throw new RuntimeException('T02 user table is missing required identity columns');
        }
    }
    $tokenColumns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='t02_acceptance_user_token'"
        . ' ORDER BY ORDINAL_POSITION'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purpose', $tokenColumns, true)) {
        throw new RuntimeException('T02 token table is missing the authentication purpose column');
    }

    $manager->rollback(1);
    $afterRollback = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 't02\\_acceptance\\_%'"
        . ' ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($afterRollback !== ['t02_acceptance_geo_migrations']) {
        throw new RuntimeException('T02 rollback did not remove only the isolated migrated structures');
    }

    $manager->migrateThrough('202608010005_create_keywords_and_questions');
    $recreated = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 't02\\_acceptance\\_%'"
        . ' ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($recreated !== $migrationTables) {
        throw new RuntimeException('Current re-migration did not recreate the exact T02 acceptance structure');
    }
} catch (Throwable $exception) {
    $failure = (
        'T02 migration failed class=' . str_replace('\\', '.', get_class($exception))
        . ' code=' . (string) $exception->getCode()
    );
} finally {
    if ($pdo instanceof PDO) {
        foreach ($dropOrder as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $after = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($before !== [] && $after !== $before) {
            $failure = 'database table list changed after T02 migration cleanup';
        }
    }
}

if ($failure !== null) {
    t02MigrationFail($failure);
}

fwrite(STDOUT, "PASS: T02 identity assertions with current empty migrate/rollback/re-migrate cleanup\n");
