<?php

declare(strict_types=1);

if (getenv('GEO_T05_SERVICE_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T05 content service test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T05_SERVICE_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T05_SERVICE_TEST_PREFIX') !== 't05_service_'
) {
    fwrite(STDERR, "FAIL: T05 content service safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\content\ContentException;
use app\common\content\KeywordQuestionService;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;

final class T05ServiceFailure extends RuntimeException
{
}

function t05ServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T05ServiceFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t05ServiceReject(callable $operation, int $status, string $message): void
{
    try {
        $operation();
    } catch (ContentException $exception) {
        t05ServiceAssert($exception->httpStatus() === $status, $message);
        return;
    }
    throw new T05ServiceFailure($message);
}

$env = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($env) ? ($env['database'] ?? []) : [];
$value = static function (string $name, $fallback) {
    $current = getenv($name);
    return $current === false ? $fallback : $current;
};
$host = (string) $value('GEO_DATABASE_HOSTNAME', $database['hostname'] ?? '');
$port = (int) $value('GEO_DATABASE_HOSTPORT', $database['hostport'] ?? 0);
$name = (string) $value('GEO_DATABASE_DATABASE', $database['database'] ?? '');
$user = (string) $value('GEO_DATABASE_USERNAME', $database['username'] ?? '');
$password = (string) $value('GEO_DATABASE_PASSWORD', $database['password'] ?? '');
$prefix = 't05_service_';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$failure = null;

try {
    $existing = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
        . " AND TABLE_NAME LIKE 't05_service\\_%' ESCAPE '\\\\'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($existing !== []) {
        foreach (['audit_logs', 'questions', 'keywords', 'company_profiles', 'user_token', 'user', 'companies', 'geo_auth_throttles', 'geo_async_jobs'] as $table) {
            if (in_array($prefix . $table, $existing, true)) {
                $pdo->exec('DELETE FROM `' . $prefix . $table . '`');
            }
        }
        $manager->rollback(5);
    }
    t05ServiceAssert(count($manager->migrateThrough('202608010005_create_keywords_and_questions')) === 5, 'service fixture applies T01 through T05');
    $now = 1785542400;
    $company = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:name,:status,:created,:updated)'
    );
    foreach (['Company A', 'Company B'] as $companyName) {
        $company->execute(['name' => $companyName, 'status' => 'normal', 'created' => $now, 'updated' => $now]);
        $companyIds[] = (int) $pdo->lastInsertId();
    }
    [$companyA, $companyB] = $companyIds;
    $contextA = TenantContext::enterprise(101, $companyA, 't05_test');
    $contextB = TenantContext::enterprise(202, $companyB, 't05_test');
    $audit = new PdoAuditLogger($pdo, $prefix);
    $gateway = new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit);
    $service = new KeywordQuestionService($pdo, $gateway, $audit, null, static function () use ($now): int {
        return $now;
    });

    $keywordA = $service->createKeyword(['keyword' => '  GEO SaaS  '], $contextA, '127.0.0.1');
    t05ServiceAssert($keywordA['keyword'] === 'GEO SaaS' && $keywordA['status'] === 'normal', 'A creates a trimmed keyword');
    $keywordB = $service->createKeyword(['keyword' => 'GEO SaaS'], $contextB);
    t05ServiceAssert($keywordB['keyword'] === $keywordA['keyword'], 'B may create the same keyword text');
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->createKeyword(['keyword' => 'GEO SaaS'], $contextA);
    }, 409, 'same-company duplicate keyword is rejected');
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->createKeyword(['keyword' => '  GEO SaaS '], $contextA);
    }, 409, 'edge-space duplicate keyword is rejected');
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->createKeyword(['keyword' => 'safe', 'company_id' => 999], $contextA);
    }, 422, 'protected company_id is rejected');
    $keywordA = $service->updateKeyword($keywordA['id'], ['keyword' => 'GEO Platform'], $contextA);
    t05ServiceAssert($keywordA['keyword'] === 'GEO Platform', 'A edits its own keyword');

    $special = $service->createKeyword(['keyword' => '100%_literal'], $contextA);
    $literal = $service->keywordPage(['search' => '%_'], $contextA);
    t05ServiceAssert($literal['total'] === 1 && $literal['items'][0]['id'] === $special['id'], 'LIKE wildcards are treated literally');
    t05ServiceAssert($service->keywordPage([], $contextA)['total'] === 2, 'A keyword list excludes B data');
    t05ServiceReject(static function () use ($service, $keywordB, $contextA): void {
        $service->keyword($keywordB['id'], $contextA);
    }, 404, 'A cannot read a B keyword');

    $questionA = $service->createQuestion($keywordA['id'], ['text' => '  What is GEO?  '], $contextA);
    t05ServiceAssert(
        $questionA['text'] === 'What is GEO?'
        && $questionA['source'] === 'manual'
        && $questionA['keyword']['id'] === $keywordA['id'],
        'question is trimmed, server-sourced as manual, and includes its keyword'
    );
    $questionA = $service->updateQuestion($questionA['id'], ['text' => 'What is GEO optimization?'], $contextA);
    t05ServiceAssert($questionA['text'] === 'What is GEO optimization?', 'A edits its own question');
    t05ServiceReject(static function () use ($service, $keywordA, $contextA): void {
        $service->createQuestion($keywordA['id'], ['text' => 'What is GEO?', 'source' => 'ai'], $contextA);
    }, 422, 'client cannot forge question source');
    t05ServiceReject(static function () use ($service, $keywordA, $contextA): void {
        $service->createQuestion($keywordA['id'], ['text' => ' what is geo optimization? '], $contextA);
    }, 409, 'same-keyword normalized question duplicate is rejected');
    $sameTextOtherKeyword = $service->createQuestion($special['id'], ['text' => 'What is GEO optimization?'], $contextA);
    t05ServiceAssert($sameTextOtherKeyword['id'] !== $questionA['id'], 'different keywords may use the same question text');
    t05ServiceReject(static function () use ($service, $keywordB, $contextA): void {
        $service->createQuestion($keywordB['id'], ['text' => 'Cross tenant'], $contextA);
    }, 404, 'A cannot attach a question to a B keyword');

    $service->setQuestionStatus($questionA['id'], 'disabled', $contextA);
    $service->setKeywordStatus($keywordA['id'], 'disabled', $contextA);
    t05ServiceAssert($service->question($questionA['id'], $contextA)['text'] === 'What is GEO optimization?', 'disabled keyword retains and exposes existing questions');
    t05ServiceReject(static function () use ($service, $keywordA, $contextA): void {
        $service->createQuestion($keywordA['id'], ['text' => 'Blocked'], $contextA);
    }, 409, 'disabled keyword rejects new questions');
    t05ServiceReject(static function () use ($service, $questionA, $contextA): void {
        $service->setQuestionStatus($questionA['id'], 'normal', $contextA);
    }, 409, 'disabled keyword rejects question enable');
    $service->setKeywordStatus($keywordA['id'], 'normal', $contextA);
    t05ServiceAssert($service->question($questionA['id'], $contextA)['status'] === 'disabled', 'keyword re-enable does not change question status');
    $service->setQuestionStatus($questionA['id'], 'normal', $contextA);
    t05ServiceAssert(count($service->activeQuestions($keywordA['id'], $contextA)) === 1, 'active question list returns only active combinations');

    $service->batchQuestionStatus([$questionA['id'], $questionA['id'], $sameTextOtherKeyword['id']], 'disabled', $contextA);
    t05ServiceAssert($service->question($questionA['id'], $contextA)['status'] === 'disabled', 'question batch status safely deduplicates IDs');
    t05ServiceReject(static function () use ($service, $questionA, $contextA): void {
        $service->batchQuestionStatus([$questionA['id'], 999999], 'normal', $contextA);
    }, 404, 'question batch with missing ID fails atomically');
    t05ServiceAssert($service->question($questionA['id'], $contextA)['status'] === 'disabled', 'failed question batch has no partial update');
    t05ServiceReject(static function () use ($service, $keywordA, $keywordB, $contextA): void {
        $service->batchKeywordStatus([$keywordA['id'], $keywordB['id']], 'disabled', $contextA);
    }, 404, 'keyword batch containing B data fails as not found');
    t05ServiceAssert($service->keyword($keywordA['id'], $contextA)['status'] === 'normal', 'cross-tenant keyword batch has no partial update');
    $service->batchKeywordStatus([$keywordA['id'], $special['id'], $special['id']], 'disabled', $contextA);
    t05ServiceAssert(
        $service->keyword($keywordA['id'], $contextA)['status'] === 'disabled'
        && $service->keyword($special['id'], $contextA)['status'] === 'disabled',
        'legal keyword batch succeeds and deduplicates IDs'
    );
    $service->batchKeywordStatus([$keywordA['id'], $special['id']], 'normal', $contextA);
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->batchKeywordStatus([], 'disabled', $contextA);
    }, 422, 'empty batch is rejected');
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->batchKeywordStatus([1, 'invalid'], 'disabled', $contextA);
    }, 422, 'illegal batch ID is rejected');
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->batchKeywordStatus(range(1, 101), 'disabled', $contextA);
    }, 422, 'over-limit batch is rejected');

    t05ServiceReject(static function () use ($service, $keywordA, $contextA): void {
        $service->deleteKeyword($keywordA['id'], $contextA);
    }, 409, 'keyword with a non-deleted question cannot be deleted');
    $service->deleteQuestion($questionA['id'], $contextA);
    $service->deleteKeyword($keywordA['id'], $contextA);
    $physicalKeyword = (int) $pdo->query(
        'SELECT COUNT(*) FROM `' . $prefix . 'keywords` WHERE `id`=' . (int) $keywordA['id']
    )->fetchColumn();
    t05ServiceAssert($physicalKeyword === 1, 'keyword delete is physical-row-preserving soft delete');
    t05ServiceReject(static function () use ($service, $keywordA, $contextA): void {
        $service->keyword($keywordA['id'], $contextA);
    }, 404, 'soft-deleted keyword is absent from resource reads');

    $questionPage = $service->questionPage($special['id'], ['status' => 'disabled'], $contextA);
    t05ServiceAssert($questionPage['total'] === 1 && $questionPage['keyword']['id'] === $special['id'], 'question pagination filters status and reports keyword');
    $questionSearch = $service->questionPage($special['id'], ['search' => 'optimization?'], $contextA);
    t05ServiceAssert($questionSearch['total'] === 1, 'question search is literal and keyword-scoped');
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->keywordPage(['sort' => 'company_id'], $contextA);
    }, 422, 'sort field is allowlisted');
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->keywordPage(['per_page' => 101], $contextA);
    }, 422, 'page size is capped');
    t05ServiceReject(static function () use ($service, $contextA): void {
        $service->keywordPage(['page' => 100001], $contextA);
    }, 422, 'page number is capped');
    $strictGatewayIds = false;
    try {
        $gateway->findMany('keywords', ['1x'], $contextA);
    } catch (TenantException $expected) {
        $strictGatewayIds = true;
    }
    t05ServiceAssert($strictGatewayIds, 'tenant gateway rejects non-canonical IDs');

    $details = $pdo->query(
        'SELECT `detail_json` FROM `' . $prefix . 'audit_logs`'
        . " WHERE `action` LIKE 'keyword_%' OR `action` LIKE 'question_%'"
    )->fetchAll(PDO::FETCH_COLUMN);
    t05ServiceAssert(count($details) >= 10, 'writes produce audit records');
    t05ServiceAssert(strpos(implode(' ', $details), 'What is GEO optimization?') === false, 'audit details do not contain full keyword or question text');
    $actions = $pdo->query(
        'SELECT DISTINCT `action` FROM `' . $prefix . 'audit_logs`'
        . " WHERE `action` LIKE 'keyword_%' OR `action` LIKE 'question_%' ORDER BY `action`"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'keyword_created', 'keyword_updated', 'keyword_status_changed',
        'keyword_batch_status_changed', 'keyword_deleted', 'question_created',
        'question_updated', 'question_status_changed', 'question_batch_status_changed', 'question_deleted',
    ] as $requiredAction) {
        t05ServiceAssert(in_array($requiredAction, $actions, true), 'audit action exists: ' . $requiredAction);
    }
    $storedSource = $pdo->query(
        'SELECT DISTINCT `source` FROM `' . $prefix . 'questions` ORDER BY `source`'
    )->fetchAll(PDO::FETCH_COLUMN);
    t05ServiceAssert($storedSource === ['manual'], 'database stores only server-controlled manual source');

    $constraintRejected = false;
    try {
        $statement = $pdo->prepare(
            'INSERT INTO `' . $prefix . 'questions`'
            . ' (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`)'
            . ' VALUES (:company,:keyword,:text,:hash,:source,:status,1,1,:created,:updated)'
        );
        $statement->execute([
            'company' => $companyA,
            'keyword' => $keywordB['id'],
            'text' => 'forged',
            'hash' => hash('sha256', 'forged', true),
            'source' => 'manual',
            'status' => 'normal',
            'created' => $now,
            'updated' => $now,
        ]);
    } catch (PDOException $exception) {
        $constraintRejected = $exception->getCode() === '23000';
    }
    t05ServiceAssert($constraintRejected, 'database rejects mismatched company and keyword tenant relationship');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        foreach (['audit_logs', 'questions', 'keywords', 'company_profiles', 'user_token', 'user', 'companies', 'geo_auth_throttles', 'geo_async_jobs'] as $table) {
            $pdo->exec('DELETE FROM `' . $prefix . $table . '`');
        }
        $manager->rollback(5);
    } catch (Throwable $cleanupFailure) {
        if ($failure === null) {
            $failure = $cleanupFailure;
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ': ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T05 service, tenant isolation, status, batch, soft-delete, and audit tests passed\n");
