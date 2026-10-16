<?php

declare(strict_types=1);

if (getenv('GEO_T02_OFFICIAL_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T02 official migration safety test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T02_OFFICIAL_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_DATABASE_PREFIX') !== 'fa_'
) {
    fwrite(STDERR, "FAIL: T02 official migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

function t02OfficialQuote(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new RuntimeException('Unexpected database identifier.');
    }
    return '`' . $identifier . '`';
}

function t02OfficialSnapshot(PDO $pdo, array $tableColumns): array
{
    $snapshot = [];
    foreach ($tableColumns as $table => $columns) {
        $rows = $pdo->query(
            'SELECT ' . implode(',', array_map('t02OfficialQuote', $columns))
            . ' FROM ' . t02OfficialQuote($table)
        )->fetchAll(PDO::FETCH_ASSOC);
        $snapshot[$table] = [
            'rows' => count($rows),
            'hash' => hash('sha256', serialize($rows)),
        ];
    }
    ksort($snapshot);
    return $snapshot;
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$databaseConfig = is_array($environment) ? ($environment['database'] ?? []) : [];
$processValue = static function (string $key, $fallback) {
    $value = getenv($key);
    return $value === false ? $fallback : $value;
};
$database = (string) $processValue('GEO_DATABASE_DATABASE', $databaseConfig['database'] ?? '');
$host = (string) $processValue('GEO_DATABASE_HOSTNAME', $databaseConfig['hostname'] ?? '');
$port = (int) $processValue('GEO_DATABASE_HOSTPORT', $databaseConfig['hostport'] ?? 0);
$username = (string) $processValue('GEO_DATABASE_USERNAME', $databaseConfig['username'] ?? '');
$password = (string) $processValue('GEO_DATABASE_PASSWORD', $databaseConfig['password'] ?? '');
if ($database === '' || $host === '' || $port < 1 || $username === '') {
    fwrite(STDERR, "FAIL: T02 official migration database configuration is incomplete\n");
    exit(1);
}

$manager = null;
$fullManager = null;
$restoreAppliedState = false;
$restoreT03 = false;
$migrationPath = ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR . 't02_official_migrations_' . getmypid();
$failure = null;
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
    $fullManager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        'fa_'
    );
    $initialStatus = $fullManager->status();
    if (($initialStatus['202607300001_create_geo_async_jobs'] ?? '') !== 'applied') {
        throw new RuntimeException('T01 migration is not applied');
    }
    if (($initialStatus['202607300004_create_company_profiles'] ?? '') === 'applied') {
        if (($initialStatus['202607300002_create_company_identity'] ?? '') !== 'applied') {
            throw new RuntimeException('T02 migration is not applied beneath T04');
        }
        foreach (['fa_companies', 'fa_geo_auth_throttles', 'fa_user', 'fa_user_token'] as $table) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
            );
            $statement->execute(['table' => $table]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('T02 structure is missing beneath T04');
            }
        }
        foreach (['company_id', 'password_hash', 'role'] as $column) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS'
                . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fa_user' AND COLUMN_NAME=:column"
            );
            $statement->execute(['column' => $column]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('T02 user structure is missing beneath T04');
            }
        }
        fwrite(
            STDOUT,
            "PASS: T02 retained official schema structure check beneath T03/T04; "
            . "full replay evidence is produced separately by tests/t02/migration_test.php\n"
        );
        exit(0);
    }
    if (($initialStatus['202607300003_enforce_tenant_isolation'] ?? '') === 'applied') {
        if ((int) $pdo->query('SELECT COUNT(*) FROM `fa_audit_logs`')->fetchColumn() > 0) {
            throw new RuntimeException('T02 replay refuses to remove non-empty T03 audit data');
        }
        if ($fullManager->rollback(1) !== ['202607300003_enforce_tenant_isolation']) {
            throw new RuntimeException('could not prepare T03 for the T02 safety replay');
        }
        $restoreT03 = true;
    }
    if (!mkdir($migrationPath, 0700) && !is_dir($migrationPath)) {
        throw new RuntimeException('could not create isolated T02 migration view');
    }
    foreach ([
        '202607300001_create_geo_async_jobs.php',
        '202607300002_create_company_identity.php',
    ] as $migration) {
        if (!copy(
            ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $migration,
            $migrationPath . DIRECTORY_SEPARATOR . $migration
        )) {
            throw new RuntimeException('could not prepare isolated T02 migration view');
        }
    }
    $manager = new MigrationManager($pdo, $migrationPath, 'fa_');
    if (($initialStatus['202607300002_create_company_identity'] ?? '') === 'applied') {
        $t02DataCounts = [
            (int) $pdo->query('SELECT COUNT(*) FROM `fa_companies`')->fetchColumn(),
            (int) $pdo->query(
                'SELECT COUNT(*) FROM `fa_user` WHERE `company_id` IS NOT NULL AND `company_id` > 0'
            )->fetchColumn(),
            (int) $pdo->query(
                "SELECT COUNT(*) FROM `fa_user_token` WHERE `purpose`='enterprise_owner'"
            )->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM `fa_geo_auth_throttles`')->fetchColumn(),
        ];
        if (array_sum($t02DataCounts) > 0) {
            throw new RuntimeException(
                'T02 official migration replay refuses to roll back non-empty identity data'
            );
        }
        if ($manager->rollback(1) !== ['202607300002_create_company_identity']) {
            throw new RuntimeException('could not prepare the applied T02 migration for safety replay');
        }
    } elseif (($initialStatus['202607300002_create_company_identity'] ?? '') !== 'pending') {
        throw new RuntimeException('T02 migration has an unexpected initial state');
    }
    $restoreAppliedState = true;

    $tables = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME NOT IN ('fa_geo_migrations')"
        . ' ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('fa_companies', $tables, true) || in_array('fa_geo_auth_throttles', $tables, true)) {
        throw new RuntimeException('T02 official tables remain outside migration history');
    }
    $tableColumns = [];
    foreach ($tables as $table) {
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
            . ' ORDER BY ORDINAL_POSITION'
        );
        $statement->execute(['table' => $table]);
        $tableColumns[$table] = $statement->fetchAll(PDO::FETCH_COLUMN);
    }
    $before = t02OfficialSnapshot($pdo, $tableColumns);

    if ($manager->migrate() !== ['202607300002_create_company_identity']) {
        throw new RuntimeException('official migrate applied an unexpected migration set');
    }
    if (t02OfficialSnapshot($pdo, $tableColumns) !== $before) {
        throw new RuntimeException('official migrate changed existing table data');
    }
    if ($manager->rollback(1) !== ['202607300002_create_company_identity']) {
        throw new RuntimeException('official rollback removed an unexpected migration set');
    }
    if (t02OfficialSnapshot($pdo, $tableColumns) !== $before) {
        throw new RuntimeException('official rollback changed existing table data');
    }
    foreach (['company_id', 'password_hash', 'role'] as $column) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fa_user' AND COLUMN_NAME=:column"
        );
        $statement->execute(['column' => $column]);
        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('official rollback left a T02 user column');
        }
    }
    if ($manager->migrate() !== ['202607300002_create_company_identity']) {
        throw new RuntimeException('official re-migrate applied an unexpected migration set');
    }
    if (t02OfficialSnapshot($pdo, $tableColumns) !== $before) {
        throw new RuntimeException('official re-migrate changed existing table data');
    }
    $finalStatus = $manager->status();
    if (
        ($finalStatus['202607300001_create_geo_async_jobs'] ?? '') !== 'applied'
        || ($finalStatus['202607300002_create_company_identity'] ?? '') !== 'applied'
    ) {
        throw new RuntimeException('final migration status is not applied for T01 and T02');
    }
    $restoreAppliedState = false;
    if ($restoreT03) {
        if ($fullManager->migrate() !== ['202607300003_enforce_tenant_isolation']) {
            throw new RuntimeException('T03 applied state was not restored after T02 replay');
        }
        $restoreT03 = false;
    }
} catch (Throwable $exception) {
    $failure = 'T02 official migration failed class='
        . str_replace('\\', '.', get_class($exception))
        . ' code=' . (string) $exception->getCode()
        . ' message=' . preg_replace(
            '/(?:password|token|secret|authorization|cookie|key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $exception->getMessage()
        );
} finally {
    if ($restoreAppliedState && $manager instanceof MigrationManager) {
        try {
            $status = $manager->status();
            if (($status['202607300002_create_company_identity'] ?? '') === 'pending') {
                $manager->migrate();
            }
        } catch (Throwable $restoreException) {
            $failure = 'T02 official migration failed and applied state could not be restored';
        }
    }
    if ($restoreT03 && $fullManager instanceof MigrationManager) {
        try {
            $status = $fullManager->status();
            if (($status['202607300002_create_company_identity'] ?? '') === 'pending') {
                if ($manager instanceof MigrationManager) {
                    $manager->migrate();
                }
            }
            $status = $fullManager->status();
            if (($status['202607300003_enforce_tenant_isolation'] ?? '') === 'pending') {
                $fullManager->migrate();
            }
        } catch (Throwable $restoreException) {
            $failure = 'T02 official migration failed and T03 applied state could not be restored';
        }
    }
    if (is_dir($migrationPath)) {
        foreach (glob($migrationPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
            unlink($path);
        }
        rmdir($migrationPath);
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: T02 official migrate/rollback/re-migrate preserved all existing table data\n");
