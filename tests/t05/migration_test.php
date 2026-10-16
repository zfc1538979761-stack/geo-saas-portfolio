<?php

declare(strict_types=1);

if (getenv('GEO_T05_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T05 migration test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T05_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T05_MIGRATION_TEST_PREFIX') !== 't05_migration_'
) {
    fwrite(STDERR, "FAIL: T05 migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T05MigrationFailure extends RuntimeException
{
}

function t05MigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T05MigrationFailure($message);
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
$password = (string) $processValue('GEO_DATABASE_PASSWORD', $database['password'] ?? '');
if ($host === '' || $port < 1 || $name === '' || $username === '') {
    fwrite(STDERR, "FAIL: T05 migration database configuration is incomplete\n");
    exit(1);
}

$prefix = 't05_migration_';
$partialPrefix = 't05_partial_';
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
$partialTables = [
    $partialPrefix . 'constraint_blocker',
    $partialPrefix . 'questions',
    $partialPrefix . 'keywords',
    $partialPrefix . 'company_profiles',
    $partialPrefix . 'audit_logs',
    $partialPrefix . 'geo_auth_throttles',
    $partialPrefix . 'user_token',
    $partialPrefix . 'user',
    $partialPrefix . 'companies',
    $partialPrefix . 'geo_async_jobs',
    $partialPrefix . 'geo_migrations',
];
$partialMigrationDir = ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR . 't05-partial-' . getmypid();
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
    foreach (array_merge($tables, $partialTables) as $table) {
        t05MigrationAssert(!in_array($table, $before, true), 'T05 migration namespace must be empty');
    }

    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    $applied = $manager->migrateThrough('202608010005_create_keywords_and_questions');
    t05MigrationAssert(count($applied) === 5, 'empty namespace applies T01 through T05');
    t05MigrationAssert(
        end($applied) === '202608010005_create_keywords_and_questions',
        'T05 is the fifth migration'
    );

    $keywordColumns = $pdo->query(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$prefix}keywords'"
        . ' ORDER BY ORDINAL_POSITION'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id', 'company_id', 'keyword', 'status', 'created_by', 'updated_by',
        'createtime', 'updatetime', 'deletetime'] as $column) {
        t05MigrationAssert(in_array($column, $keywordColumns, true), 'keyword column exists: ' . $column);
    }

    $questionColumns = $pdo->query(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$prefix}questions'"
        . ' ORDER BY ORDINAL_POSITION'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id', 'company_id', 'keyword_id', 'text', 'text_hash', 'source', 'status',
        'created_by', 'updated_by', 'createtime', 'updatetime', 'deletetime'] as $column) {
        t05MigrationAssert(in_array($column, $questionColumns, true), 'question column exists: ' . $column);
    }

    $indexes = $pdo->query(
        'SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) columns_list,NON_UNIQUE'
        . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()'
        . " AND TABLE_NAME IN ('{$prefix}keywords','{$prefix}questions')"
        . ' GROUP BY TABLE_NAME,INDEX_NAME,NON_UNIQUE'
    )->fetchAll();
    $indexMap = [];
    foreach ($indexes as $index) {
        $indexMap[$index['TABLE_NAME'] . ':' . $index['INDEX_NAME']] = $index;
    }
    t05MigrationAssert(
        ($indexMap[$prefix . 'keywords:uq_keywords_company_keyword']['columns_list'] ?? '')
            === 'company_id,keyword',
        'keyword unique key includes tenant'
    );
    t05MigrationAssert(
        ($indexMap[$prefix . 'questions:uq_questions_keyword_hash']['columns_list'] ?? '')
            === 'company_id,keyword_id,text_hash',
        'question unique key includes tenant and keyword'
    );
    t05MigrationAssert(
        strpos((string) ($indexMap[$prefix . 'keywords:idx_keywords_company_status_created']['columns_list'] ?? ''),
            'company_id,status,createtime') === 0,
        'keyword list index covers tenant status and creation order'
    );
    t05MigrationAssert(
        strpos((string) ($indexMap[$prefix . 'questions:idx_questions_company_keyword_status']['columns_list'] ?? ''),
            'company_id,keyword_id,status') === 0,
        'question list index covers tenant keyword and status'
    );

    $foreignKeys = $pdo->query(
        'SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME'
        . ' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()'
        . " AND TABLE_NAME IN ('{$prefix}keywords','{$prefix}questions')"
        . ' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION'
    )->fetchAll();
    $foreignText = json_encode($foreignKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    t05MigrationAssert(substr_count((string) $foreignText, $prefix . 'companies') === 2, 'both tables reference companies');
    t05MigrationAssert(
        strpos((string) $foreignText, $prefix . 'keywords') !== false,
        'question has a keyword foreign key'
    );

    $now = time();
    $company = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:name,:status,:created,:updated)'
    );
    $company->execute(['name' => 'T05 Migration A', 'status' => 'normal', 'created' => $now, 'updated' => $now]);
    $companyA = (int) $pdo->lastInsertId();
    $company->execute(['name' => 'T05 Migration B', 'status' => 'normal', 'created' => $now, 'updated' => $now]);
    $companyB = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO `' . $prefix . 'keywords`'
        . ' (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`)'
        . " VALUES (:company_id,'same','normal',1,1,:created,:updated)"
    )->execute(['company_id' => $companyA, 'created' => $now, 'updated' => $now]);
    $keywordId = (int) $pdo->lastInsertId();
    $duplicateRejected = false;
    try {
        $pdo->prepare(
            'INSERT INTO `' . $prefix . 'keywords`'
            . ' (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`)'
            . " VALUES (:company_id,'same','normal',1,1,:created,:updated)"
        )->execute(['company_id' => $companyA, 'created' => $now, 'updated' => $now]);
    } catch (PDOException $expected) {
        $duplicateRejected = true;
    }
    t05MigrationAssert($duplicateRejected, 'database rejects duplicate keyword in one tenant');
    $pdo->prepare(
        'INSERT INTO `' . $prefix . 'keywords`'
        . ' (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`)'
        . " VALUES (:company_id,'same','normal',1,1,:created,:updated)"
    )->execute(['company_id' => $companyB, 'created' => $now, 'updated' => $now]);

    $crossTenantRejected = false;
    try {
        $pdo->prepare(
            'INSERT INTO `' . $prefix . 'questions`'
            . ' (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`)'
            . " VALUES (:company_id,:keyword_id,'q',UNHEX(SHA2('q',256)),'manual','normal',1,1,:created,:updated)"
        )->execute(['company_id' => $companyB, 'keyword_id' => $keywordId, 'created' => $now, 'updated' => $now]);
    } catch (PDOException $expected) {
        $crossTenantRejected = true;
    }
    t05MigrationAssert($crossTenantRejected, 'database rejects cross-tenant keyword association');

    $pdo->exec('UPDATE `' . $prefix . 'geo_migrations` SET `batch`=1');
    $pdo->exec(
        'UPDATE `' . $prefix . 'geo_migrations` SET `batch`=2'
        . " WHERE `migration`='202608010005_create_keywords_and_questions'"
    );
    t05MigrationAssert(
        $manager->rollback(1) === ['202608010005_create_keywords_and_questions'],
        'latest rollback removes only T05'
    );
    t05MigrationAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}companies`")->fetchColumn() === 2,
        'T05 rollback preserves T02 companies'
    );
    t05MigrationAssert(
        $manager->migrateThrough('202608010005_create_keywords_and_questions') === ['202608010005_create_keywords_and_questions'],
        'T05 re-migrates after rollback'
    );

    t05MigrationAssert(mkdir($partialMigrationDir, 0700, true), 'create isolated partial-failure migration directory');
    $migrationFiles = glob(ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.php');
    sort($migrationFiles, SORT_STRING);
    foreach (array_slice($migrationFiles, 0, 4) as $migrationFile) {
        t05MigrationAssert(
            copy($migrationFile, $partialMigrationDir . DIRECTORY_SEPARATOR . basename($migrationFile)),
            'copy prerequisite migration for partial-failure test'
        );
    }
    $partialManager = new MigrationManager($pdo, $partialMigrationDir, $partialPrefix);
    t05MigrationAssert(count($partialManager->migrateThrough('202607300004_create_company_profiles')) === 4, 'partial-failure fixture applies T01 through T04');

    $blockingConstraint = 'fk_geo_question_company_' . substr(sha1($partialPrefix), 0, 10);
    $pdo->exec(
        'CREATE TABLE `' . $partialPrefix . 'constraint_blocker` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`company_id` BIGINT UNSIGNED NOT NULL,'
        . 'PRIMARY KEY (`id`),'
        . 'CONSTRAINT `' . $blockingConstraint . '` FOREIGN KEY (`company_id`) REFERENCES `'
        . $partialPrefix . 'companies` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $t05Migration = require ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations'
        . DIRECTORY_SEPARATOR . '202608010005_create_keywords_and_questions.php';
    $partialFailure = false;
    try {
        $t05Migration->up($pdo, $partialPrefix);
    } catch (Throwable $expected) {
        $partialFailure = true;
    }
    t05MigrationAssert($partialFailure, 'forced second-table DDL failure is observed');
    $tableExists = static function (PDO $pdo, string $table): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    };
    t05MigrationAssert(!$tableExists($pdo, $partialPrefix . 'keywords'), 'failed T05 up removes the first created table');
    t05MigrationAssert(!$tableExists($pdo, $partialPrefix . 'questions'), 'failed T05 up leaves no second table');
    $pdo->exec('DROP TABLE `' . $partialPrefix . 'constraint_blocker`');
    $t05Migration->up($pdo, $partialPrefix);
    t05MigrationAssert(
        $tableExists($pdo, $partialPrefix . 'keywords') && $tableExists($pdo, $partialPrefix . 'questions'),
        'T05 up succeeds after the DDL blocker is removed'
    );
    $t05Migration->down($pdo, $partialPrefix);
    fwrite(STDOUT, "PASS: T05 schema, constraints, half-DDL cleanup, rollback-only-T05, and re-migrate\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (array_merge($tables, $partialTables) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $after = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($before !== [] && $after !== $before) {
        $failure = new T05MigrationFailure('T05 migration cleanup changed database table list');
    }
    if (is_dir($partialMigrationDir)) {
        foreach (glob($partialMigrationDir . DIRECTORY_SEPARATOR . '*') as $partialFile) {
            if (is_file($partialFile)) {
                unlink($partialFile);
            }
        }
        rmdir($partialMigrationDir);
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
