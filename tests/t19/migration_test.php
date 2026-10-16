<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);

$migrationPath = ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '202608210024_create_t19_geo_core_phase1.php';
$baselineMigrationPath = ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '202608210025_add_t19_visibility_baseline.php';
$intelligenceMigrationPath = ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '202608220026_create_company_intelligence_onboarding.php';
if (!is_file($migrationPath)) {
    fwrite(STDERR, "FAIL: T19 phase-1 migration is missing\n");
    exit(1);
}
if (!is_file($baselineMigrationPath)) {
    fwrite(STDERR, "FAIL: T19 baseline migration is missing\n");
    exit(1);
}
if (!is_file($intelligenceMigrationPath)) {
    fwrite(STDERR, "FAIL: T19 company intelligence migration is missing\n");
    exit(1);
}

require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;
use think\Env;

final class T19MigrationFailure extends RuntimeException
{
}

function t19MigrationAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T19MigrationFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

$process = static function (string $name, $fallback) {
    $value = getenv($name);
    return $value !== false ? $value : $fallback;
};
$host = (string) $process('GEO_DATABASE_HOSTNAME', Env::get('database.hostname', ''));
$port = (int) $process('GEO_DATABASE_HOSTPORT', Env::get('database.hostport', 0));
$database = (string) $process('GEO_DATABASE_DATABASE', Env::get('database.database', ''));
$user = (string) $process('GEO_DATABASE_USERNAME', Env::get('database.username', ''));
$password = (string) $process('GEO_DATABASE_PASSWORD', Env::get('database.password', ''));
if ($host !== '127.0.0.1' || $port < 1 || $port === 3306 || preg_match('/_test$/D', $database) !== 1) {
    fwrite(STDERR, "FAIL: T19 migration test requires an isolated non-3306 _test database\n");
    exit(1);
}

$prefix = 't19_migration_';
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$failure = null;

try {
    $manager->refresh();
    $status = $manager->status();
    t19MigrationAssert(($status['202608210024_create_t19_geo_core_phase1'] ?? '') === 'applied', 'T19 migration is applied');
    t19MigrationAssert(($status['202608210025_add_t19_visibility_baseline'] ?? '') === 'applied', 'T19 Baseline migration is applied');
    t19MigrationAssert(($status['202608220026_create_company_intelligence_onboarding'] ?? '') === 'applied', 'T19 company intelligence migration is applied');
    $tables = [
        'geo_question_generation_batches', 'geo_answer_versions', 'geo_visibility_plans',
        'geo_visibility_checks', 'geo_visibility_results', 'geo_visibility_comparisons',
        'geo_optimization_suggestions',
    ];
    foreach ($tables as $table) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$prefix . $table]);
        t19MigrationAssert((int) $statement->fetchColumn() === 1, $table . ' exists');
    }
    foreach ([
        'company_discovery_runs', 'company_discovery_candidates', 'company_candidate_sources',
        'company_profile_drafts', 'company_profile_draft_facts',
    ] as $table) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$prefix . $table]);
        t19MigrationAssert((int) $statement->fetchColumn() === 1, $table . ' exists');
    }
    foreach ([
        ['questions', 'generation_batch_id'], ['questions', 'reviewed_at'], ['article_versions', 'geo_answer_version_id'],
        ['geo_visibility_checks', 'question_snapshot'], ['geo_visibility_checks', 'brand_snapshot'],
        ['geo_visibility_checks', 'raw_response'], ['geo_visibility_checks', 'response_history_json'],
        ['geo_visibility_results', 'question_snapshot'], ['geo_visibility_results', 'brand_snapshot'],
        ['geo_visibility_plans', 'baseline_result_id'],
    ] as $column) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $statement->execute([$prefix . $column[0], $column[1]]);
        t19MigrationAssert((int) $statement->fetchColumn() === 1, $column[0] . '.' . $column[1] . ' exists');
    }
    $default = $pdo->query(
        "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"
        . " AND TABLE_NAME='{$prefix}geo_visibility_plans' AND COLUMN_NAME='interval_days'"
    )->fetchColumn();
    t19MigrationAssert((string) $default === '7', 'visibility schedule defaults to seven days');

    $baselineForeignKey = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()'
        . ' AND TABLE_NAME=? AND COLUMN_NAME=\'baseline_result_id\' AND REFERENCED_TABLE_NAME=? AND REFERENCED_COLUMN_NAME=\'id\''
    );
    $baselineForeignKey->execute([$prefix . 'geo_visibility_plans', $prefix . 'geo_visibility_results']);
    t19MigrationAssert((int) $baselineForeignKey->fetchColumn() === 1, 'visibility plan Baseline has a tenant-safe result foreign key');

    foreach ([
        ['company_discovery_runs', 'input_snapshot_json'],
        ['company_discovery_runs', 'network_executed'],
        ['company_discovery_candidates', 'confirmed_by'],
        ['company_candidate_sources', 'source_url'],
        ['company_profile_drafts', 'profile_baseline_json'],
        ['company_profile_drafts', 'knowledge_item_id'],
        ['company_profile_draft_facts', 'source_ids_json'],
    ] as $column) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $statement->execute([$prefix . $column[0], $column[1]]);
        t19MigrationAssert((int) $statement->fetchColumn() === 1, $column[0] . '.' . $column[1] . ' exists');
    }
    $tenantForeignKey = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()'
        . ' AND TABLE_NAME=? AND COLUMN_NAME=\'candidate_id\' AND REFERENCED_TABLE_NAME=? AND REFERENCED_COLUMN_NAME=\'id\''
    );
    $tenantForeignKey->execute([$prefix . 'company_candidate_sources', $prefix . 'company_discovery_candidates']);
    t19MigrationAssert((int) $tenantForeignKey->fetchColumn() === 1, 'candidate sources have a tenant-safe candidate foreign key');

    $nextBatch = (int) $pdo->query('SELECT COALESCE(MAX(`batch`),0)+1 FROM `' . $prefix . 'geo_migrations`')->fetchColumn();
    $batch = $pdo->prepare('UPDATE `' . $prefix . 'geo_migrations` SET `batch`=? WHERE `migration`=?');
    $batch->execute([$nextBatch, '202608220026_create_company_intelligence_onboarding']);
    t19MigrationAssert($manager->rollback(1) === ['202608220026_create_company_intelligence_onboarding'], 'T19 company intelligence migration rolls back independently');
    t19MigrationAssert($manager->migrateThrough('202608220026_create_company_intelligence_onboarding') === ['202608220026_create_company_intelligence_onboarding'], 'T19 company intelligence migration reapplies');

    $nextBatch = (int) $pdo->query('SELECT COALESCE(MAX(`batch`),0)+1 FROM `' . $prefix . 'geo_migrations`')->fetchColumn();
    $batch->execute([$nextBatch, '202608210025_add_t19_visibility_baseline']);
    t19MigrationAssert($manager->rollback(1) === ['202608210025_add_t19_visibility_baseline'], 'T19 Baseline migration rolls back independently');
    t19MigrationAssert($manager->migrateThrough('202608210025_add_t19_visibility_baseline') === ['202608210025_add_t19_visibility_baseline'], 'T19 Baseline migration reapplies');

    $nextBatch = (int) $pdo->query('SELECT COALESCE(MAX(`batch`),0)+1 FROM `' . $prefix . 'geo_migrations`')->fetchColumn();
    $batch->execute([$nextBatch, '202608210025_add_t19_visibility_baseline']);
    t19MigrationAssert($manager->rollback(1) === ['202608210025_add_t19_visibility_baseline'], 'T19 Baseline migration is removed before phase-1 rollback testing');
    $nextBatch = (int) $pdo->query('SELECT COALESCE(MAX(`batch`),0)+1 FROM `' . $prefix . 'geo_migrations`')->fetchColumn();
    $batch->execute([$nextBatch, '202608210024_create_t19_geo_core_phase1']);
    $rolled = $manager->rollback(1);
    t19MigrationAssert($rolled === ['202608210024_create_t19_geo_core_phase1'], 'T19 migration rolls back as one batch');
    t19MigrationAssert($manager->migrateThrough('202608210024_create_t19_geo_core_phase1') === ['202608210024_create_t19_geo_core_phase1'], 'T19 migration reapplies');

    $nextBatch = (int) $pdo->query('SELECT COALESCE(MAX(`batch`),0)+1 FROM `' . $prefix . 'geo_migrations`')->fetchColumn();
    $batch->execute([$nextBatch, '202608210024_create_t19_geo_core_phase1']);
    t19MigrationAssert($manager->rollback(1) === ['202608210024_create_t19_geo_core_phase1'], 'T19 can be prepared for interrupted-DDL recovery test');
    $pdo->exec('ALTER TABLE `' . $prefix . 'questions` ADD KEY `idx_questions_company_generation_batch` (`company_id`,`id`)');
    $migration = require $migrationPath;
    $interrupted = false;
    try { $migration->up($pdo, $prefix); } catch (Throwable $expected) { $interrupted = true; }
    t19MigrationAssert($interrupted, 'injected mid-migration DDL conflict fails the migration');
    $leftover = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$prefix}geo_question_generation_batches'")->fetchColumn();
    t19MigrationAssert((int) $leftover === 0, 'failed T19 migration removes all partial tables');
    $partialColumn = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$prefix}questions' AND COLUMN_NAME='generation_batch_id'")->fetchColumn();
    t19MigrationAssert((int) $partialColumn === 0, 'failed T19 migration removes all partial columns');
    $pdo->exec('ALTER TABLE `' . $prefix . 'questions` DROP INDEX `idx_questions_company_generation_batch`');
    t19MigrationAssert($manager->migrateThrough('202608210024_create_t19_geo_core_phase1') === ['202608210024_create_t19_geo_core_phase1'], 'T19 migration reapplies after an interrupted DDL attempt');
    t19MigrationAssert($manager->migrateThrough('202608210025_add_t19_visibility_baseline') === ['202608210025_add_t19_visibility_baseline'], 'T19 Baseline migration reapplies after phase-1 recovery');
    fwrite(STDOUT, "PASS: T19 migration integration\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try { $manager->refresh(); } catch (Throwable $ignored) {}
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
