<?php

declare(strict_types=1);

if (getenv('GEO_T06_SERVICE_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T06 content service test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T06_SERVICE_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T06_SERVICE_TEST_PREFIX') !== 't06_service_'
) {
    fwrite(STDERR, "FAIL: T06 content service safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\content\ArticleService;
use app\common\content\ContentException;
use app\common\content\KnowledgeItemService;
use app\common\content\PromptTemplateService;
use app\common\content\foundation\ContentAssetPolicy;
use app\common\content\foundation\EnterpriseFileValidator;
use app\common\content\storage\MockMediaMarketStorageAdapter;
use app\common\content\storage\StorageAdapter;
use app\common\content\storage\StorageException;
use app\common\content\storage\StorageReadResult;
use app\common\content\storage\StoredObject;
use app\common\content\foundation\ValidatedUpload;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantResourceCatalog;

final class T06ServiceFailure extends RuntimeException {}
final class T06FailingStorageAdapter implements StorageAdapter
{
    public function upload(ValidatedUpload $upload, TenantContext $context): StoredObject { throw new StorageException('expected test failure'); }
    public function read(StoredObject $object, TenantContext $context): StorageReadResult { throw new StorageException('expected test failure'); }
    public function delete(StoredObject $object, TenantContext $context): void { throw new StorageException('expected test failure'); }
}

function t06ServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T06ServiceFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t06ServiceReject(callable $operation, int $status, string $message): void
{
    try {
        $operation();
    } catch (ContentException $exception) {
        t06ServiceAssert($exception->httpStatus() === $status, $message);
        return;
    }
    throw new T06ServiceFailure($message);
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
if ($host !== '127.0.0.1' || $port === 3306 || $name !== getenv('GEO_T06_SERVICE_TEST_DATABASE')) {
    fwrite(STDERR, "FAIL: T06 service test requires an explicit isolated non-3306 database\n");
    exit(1);
}

$prefix = 't06_service_';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'geo-saas-t06-service-' . bin2hex(random_bytes(8));
$failure = null;

try {
    $auditTableExists = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $auditTableExists->execute([$prefix . 'audit_logs']);
    if ((int) $auditTableExists->fetchColumn() > 0) {
        $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`');
    }
    $applied = $manager->refresh();
    t06ServiceAssert(
        count($applied) > 0 && in_array('202608010006_create_content_version_foundation', $applied, true),
        'service fixture applies current schema including T06'
    );
    $now = 1785542400;
    $company = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,?)'
    );
    $company->execute(['Company A', 'normal', $now, $now]);
    $companyA = (int) $pdo->lastInsertId();
    $company->execute(['Company B', 'normal', $now, $now]);
    $companyB = (int) $pdo->lastInsertId();
    $contextA = TenantContext::enterprise(101, $companyA, 't06_test');
    $contextB = TenantContext::enterprise(202, $companyB, 't06_test');

    $insertKeyword = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'keywords`'
        . ' (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?)'
    );
    $insertQuestion = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'questions`'
        . ' (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $insertKeyword->execute([$companyA, 'GEO A', 'normal', 101, 101, $now, $now]);
    $keywordA = (int) $pdo->lastInsertId();
    $insertQuestion->execute([$companyA, $keywordA, 'How does GEO work?', hash('sha256', 'a', true), 'manual', 'normal', 101, 101, $now, $now]);
    $questionA = (int) $pdo->lastInsertId();
    $insertKeyword->execute([$companyB, 'GEO B', 'normal', 202, 202, $now, $now]);
    $keywordB = (int) $pdo->lastInsertId();
    $insertQuestion->execute([$companyB, $keywordB, 'B question', hash('sha256', 'b', true), 'manual', 'normal', 202, 202, $now, $now]);
    $questionB = (int) $pdo->lastInsertId();

    $audit = new PdoAuditLogger($pdo, $prefix);
    $gateway = new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit);
    $storage = new MockMediaMarketStorageAdapter('testing', $tempRoot, true);
    $config = require ROOT_PATH . 'application' . DIRECTORY_SEPARATOR . 'extra' . DIRECTORY_SEPARATOR . 'geo_content.php';
    $validator = new EnterpriseFileValidator(new ContentAssetPolicy($config['files']));
    $clock = static function () use ($now): int { return $now; };
    $knowledge = new KnowledgeItemService($pdo, $gateway, $audit, $storage, $validator, $config, $clock);
    $prompts = new PromptTemplateService($pdo, $gateway, $audit, $config, $clock);
    $articles = new ArticleService($pdo, $gateway, $audit, $config, $clock);

    $text = $knowledge->createText(['title' => '  Facts  ', 'content' => '<script>alert(1)</script> GEO'], $contextA);
    t06ServiceAssert($text['type'] === 'text' && $text['title'] === 'Facts', 'text knowledge is created and normalized');
    t06ServiceAssert(strpos($knowledge->preview($text['id'], $contextA)['html'], '<script>') === false, 'knowledge preview escapes active HTML');
    t06ServiceReject(static function () use ($knowledge, $contextA): void {
        $knowledge->createText(['title' => '', 'content' => 'body'], $contextA);
    }, 422, 'empty knowledge title is rejected');
    t06ServiceReject(static function () use ($knowledge, $contextA): void {
        $knowledge->createText(['title' => 'x', 'content' => 'body', 'company_id' => 999], $contextA);
    }, 422, 'knowledge company_id is protected');

    $pngPath = $tempRoot . '-source.png';
    file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $file = $knowledge->createFile(
        ['title' => 'Logo'], $pngPath, 'logo.png', 'image/png', (int) filesize($pngPath), $contextA
    );
    t06ServiceAssert($file['type'] === 'image' && $file['file']['provider'] === 'media_market', 'validated image is stored through MediaMarket Mock');
    t06ServiceAssert($knowledge->readFile($file['id'], $contextA)->mime() === 'image/png', 'tenant-controlled file read succeeds');
    $storedBeforeDuplicate = count(glob($storage->temporaryRoot() . DIRECTORY_SEPARATOR . '*.bin') ?: []);
    t06ServiceReject(static function () use ($knowledge, $pngPath, $contextA): void {
        $knowledge->createFile(['title' => 'Duplicate'], $pngPath, 'duplicate.png', 'image/png', (int) filesize($pngPath), $contextA);
    }, 409, 'duplicate database save failure is reported');
    t06ServiceAssert(count(glob($storage->temporaryRoot() . DIRECTORY_SEPARATOR . '*.bin') ?: []) === $storedBeforeDuplicate, 'database save failure compensates the new remote object');
    $failingKnowledge = new KnowledgeItemService($pdo, $gateway, $audit, new T06FailingStorageAdapter(), $validator, $config, $clock);
    t06ServiceReject(static function () use ($failingKnowledge, $pngPath, $contextA): void {
        $failingKnowledge->createFile(['title' => 'Unavailable'], $pngPath, 'unavailable.png', 'image/png', (int) filesize($pngPath), $contextA);
    }, 503, 'storage upload failure is surfaced without a database row');
    t06ServiceReject(static function () use ($knowledge, $file, $contextB): void {
        $knowledge->readFile($file['id'], $contextB);
    }, 404, 'B cannot read A file knowledge');
    t06ServiceAssert($knowledge->page(['search' => '%_'], $contextA)['total'] === 0, 'knowledge LIKE wildcards are literal');

    $promptA1 = $prompts->create(['name' => 'GEO brief', 'scene' => 'article', 'content' => 'Version one'], $contextA);
    t06ServiceAssert($promptA1['version_no'] === 1 && strlen($promptA1['logical_key']) === 32, 'prompt creation produces immutable version one');
    $promptA2 = $prompts->createVersion($promptA1['logical_key'], ['name' => 'GEO brief', 'scene' => 'article', 'content' => 'Version two'], $contextA);
    t06ServiceAssert($promptA2['version_no'] === 2, 'prompt edit creates version two');
    t06ServiceAssert($prompts->version($promptA1['id'], $contextA)['content'] === 'Version one', 'old prompt body remains unchanged');
    t06ServiceReject(static function () use ($prompts, $promptA1, $contextA): void {
        $prompts->createVersion($promptA1['logical_key'], ['name' => 'x', 'scene' => 'article', 'content' => 'x', 'version_no' => 99], $contextA);
    }, 422, 'client cannot forge prompt version number');
    t06ServiceReject(static function () use ($prompts, $promptA1, $contextB): void {
        $prompts->versions($promptA1['logical_key'], $contextB);
    }, 404, 'B cannot read A prompt history');

    $article = $articles->create([
        'keyword_id' => $keywordA,
        'question_id' => $questionA,
        'title' => 'Manual draft',
        'content' => '<img src=x onerror=alert(1)> body',
        'prompt_template_id' => $promptA2['id'],
        'knowledge_item_ids' => [$text['id'], $file['id']],
    ], $contextA);
    t06ServiceAssert($article['status'] === 'draft' && $article['current_version']['version_no'] === 1, 'article creation atomically produces draft and version one');
    t06ServiceAssert($article['current_version']['source'] === 'manual' && count($article['current_version']['knowledge']) === 2, 'server stores manual source and traceable knowledge snapshots');
    $safeArticlePreview = $articles->preview($article['id'], null, $contextA)['html'];
    t06ServiceAssert(strpos($safeArticlePreview, '<img') === false && strpos($safeArticlePreview, '&lt;img') !== false, 'article preview escapes executable markup');
    t06ServiceReject(static function () use ($articles, $keywordB, $questionB, $promptA2, $contextA): void {
        $articles->create(['keyword_id' => $keywordB, 'question_id' => $questionB, 'title' => 'x', 'content' => 'x', 'prompt_template_id' => $promptA2['id'], 'knowledge_item_ids' => []], $contextA);
    }, 404, 'A cannot attach B keyword and question');
    t06ServiceReject(static function () use ($articles, $keywordA, $questionB, $promptA2, $contextA): void {
        $articles->create(['keyword_id' => $keywordA, 'question_id' => $questionB, 'title' => 'x', 'content' => 'x', 'prompt_template_id' => $promptA2['id'], 'knowledge_item_ids' => []], $contextA);
    }, 404, 'mismatched question and keyword is rejected without tenant leakage');
    t06ServiceReject(static function () use ($articles, $keywordA, $questionA, $promptA2, $contextA): void {
        $articles->create(['keyword_id' => $keywordA, 'question_id' => $questionA, 'title' => 'x', 'content' => 'x', 'prompt_template_id' => $promptA2['id'], 'knowledge_item_ids' => [], 'source' => 'ai'], $contextA);
    }, 422, 'client cannot forge AI article source');

    $edited = $articles->edit($article['id'], [
        'current_version_id' => $article['current_version_id'], 'title' => 'Manual draft v2', 'content' => 'Second body',
        'prompt_template_id' => $promptA2['id'], 'knowledge_item_ids' => [$text['id']],
    ], $contextA);
    t06ServiceAssert($edited['current_version']['version_no'] === 2 && $edited['current_version_id'] !== $article['current_version_id'], 'every effective edit creates a new current version');
    t06ServiceAssert($articles->version($article['id'], $article['current_version_id'], $contextA)['content'] !== 'Second body', 'old article version remains unchanged');
    t06ServiceReject(static function () use ($articles, $article, $promptA2, $contextA): void {
        $articles->edit($article['id'], ['current_version_id' => $article['current_version_id'], 'title' => 'stale', 'content' => 'stale', 'prompt_template_id' => $promptA2['id'], 'knowledge_item_ids' => []], $contextA);
    }, 409, 'stale concurrent edit is rejected');
    $restored = $articles->restore($article['id'], $article['current_version_id'], $edited['current_version_id'], $contextA);
    t06ServiceAssert($restored['current_version']['version_no'] === 3 && $restored['current_version']['content'] === $article['current_version']['content'], 'restore copies old content into a new version');
    t06ServiceAssert(count($articles->versions($article['id'], $contextA)) === 3, 'version history is complete');
    t06ServiceAssert($articles->setStatus($article['id'], 'disabled', $contextA)['status'] === 'disabled', 'draft can transition to disabled');
    t06ServiceAssert($articles->setStatus($article['id'], 'draft', $contextA)['status'] === 'draft', 'disabled can transition back to draft');
    t06ServiceReject(static function () use ($articles, $article, $contextB): void {
        $articles->detail($article['id'], $contextB);
    }, 404, 'B cannot read A article or versions');

    $knowledge->delete($text['id'], $contextA);
    t06ServiceAssert($articles->version($article['id'], $article['current_version_id'], $contextA)['knowledge'][0]['snapshot']['title'] === 'Facts', 'soft-deleted knowledge remains traceable from history');
    t06ServiceReject(static function () use ($knowledge, $text, $contextA): void {
        $knowledge->detail($text['id'], $contextA);
    }, 404, 'soft-deleted knowledge leaves normal resource reads');

    $actions = $pdo->query('SELECT `action`,`detail_json` FROM `' . $prefix . 'audit_logs` ORDER BY `id`')->fetchAll();
    $actionNames = array_column($actions, 'action');
    foreach (['knowledge_created', 'file_uploaded', 'prompt_created', 'prompt_version_created', 'article_created', 'article_version_created', 'article_version_restored', 'article_status_changed'] as $required) {
        t06ServiceAssert(in_array($required, $actionNames, true), 'audit action exists: ' . $required);
    }
    $auditJson = implode(' ', array_column($actions, 'detail_json'));
    t06ServiceAssert(strpos($auditJson, 'Second body') === false && strpos($auditJson, 'Version two') === false, 'audit details exclude full protected content');
    t06ServiceAssert((int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'articles` WHERE `current_version_id` IS NULL')->fetchColumn() === 0, 'no committed article has a missing current version');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        if (isset($pngPath) && is_file($pngPath)) { unlink($pngPath); }
        $auditTableExists = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $auditTableExists->execute([$prefix . 'audit_logs']);
        if ((int) $auditTableExists->fetchColumn() > 0) {
            $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`');
        }
        $manager->rollback(6);
    } catch (Throwable $cleanupFailure) {
        if ($failure === null) { $failure = $cleanupFailure; }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ': ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T06 knowledge, prompt, article version, tenant, storage, and audit service tests passed\n");
