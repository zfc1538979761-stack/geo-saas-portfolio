<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\api\controller\GeoAnswer;
use app\api\controller\GeoVisibility;
use app\common\migration\MigrationManager;
use think\Env;

final class T19EnterpriseApiFailure extends RuntimeException
{
}

function t19EnterpriseApiAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T19EnterpriseApiFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t19EnterpriseApiRequest(string $base, string $method, string $path, array $data = [], array $headers = []): array
{
    $body = $method === 'GET' ? '' : http_build_query($data);
    $headerLines = array_merge(['Accept: application/json'], $headers);
    if ($body !== '') { $headerLines[] = 'Content-Type: application/x-www-form-urlencoded'; }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headerLines),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 10,
        'follow_location' => 0,
    ]]);
    $raw = @file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', (string) ($responseHeaders[0] ?? ''), $match);
    return [
        'status' => isset($match[1]) ? (int) $match[1] : 0,
        'payload' => json_decode((string) $raw, true),
        'body' => (string) $raw,
    ];
}

function t19EnterpriseApiCleanup(PDO $pdo, string $prefix): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $statement = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ?');
    $statement->execute([str_replace(['_', '%'], ['\\_', '\\%'], $prefix) . '%']);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function t19EnterpriseApiStartServer(array $environment): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    t19EnterpriseApiAssert($socket !== false, 'reserve enterprise API HTTP port');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr((string) $name, ':'), 1);
    $base = 'http://127.0.0.1:' . $port;
    $out = tempnam(sys_get_temp_dir(), 't19-enterprise-api-out-');
    $err = tempnam(sys_get_temp_dir(), 't19-enterprise-api-err-');
    $pipes = [];
    $process = proc_open([
        PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', ROOT_PATH . 'public',
        ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'router.php',
    ], [0 => ['pipe', 'r'], 1 => ['file', $out, 'a'], 2 => ['file', $err, 'a']], $pipes, ROOT_PATH, $environment, ['bypass_shell' => true]);
    t19EnterpriseApiAssert(is_resource($process), 'start enterprise API HTTP server');
    $ready = false;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        if (t19EnterpriseApiRequest($base, 'GET', '/api/version')['status'] === 200) { $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) {
        $error = is_file($err) ? (string) file_get_contents($err) : '';
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        @unlink($out); @unlink($err);
        throw new T19EnterpriseApiFailure('enterprise API server did not become ready: ' . substr($error, 0, 500));
    }
    return ['process' => $process, 'base' => $base, 'out' => $out, 'err' => $err];
}

function t19EnterpriseApiStopServer(array $server): void
{
    if (isset($server['process']) && is_resource($server['process'])) {
        proc_terminate($server['process']);
        proc_close($server['process']);
    }
    @unlink((string) ($server['out'] ?? ''));
    @unlink((string) ($server['err'] ?? ''));
}

try {
    t19EnterpriseApiAssert(class_exists(GeoAnswer::class), 'GEO answer enterprise API controller exists');
    t19EnterpriseApiAssert(class_exists(GeoVisibility::class), 'GEO visibility enterprise API controller exists');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

if (getenv('GEO_T19_ENTERPRISE_API_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T19 enterprise API HTTP test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T19_ENTERPRISE_API_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T19_ENTERPRISE_API_TEST_PREFIX') !== 't19_enterprise_api_'
) {
    fwrite(STDERR, "FAIL: T19 enterprise API HTTP test safety gate rejected the environment\n");
    exit(1);
}

$processValue = static function (string $name, $fallback) {
    $value = getenv($name);
    return $value !== false ? $value : $fallback;
};
$host = (string) $processValue('GEO_DATABASE_HOSTNAME', Env::get('database.hostname', ''));
$port = (int) $processValue('GEO_DATABASE_HOSTPORT', Env::get('database.hostport', 0));
$database = (string) $processValue('GEO_DATABASE_DATABASE', Env::get('database.database', ''));
$username = (string) $processValue('GEO_DATABASE_USERNAME', Env::get('database.username', ''));
$password = (string) $processValue('GEO_DATABASE_PASSWORD', Env::get('database.password', ''));
if ($host !== '127.0.0.1' || $port === 3306 || preg_match('/_test$/D', $database) !== 1) {
    fwrite(STDERR, "FAIL: T19 enterprise API HTTP test requires an isolated non-3306 _test database\n");
    exit(1);
}

$prefix = 't19_enterprise_api_';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server = [];
$failure = null;
$httpDatabaseUser = null;

try {
    t19EnterpriseApiCleanup($pdo, $prefix);
    (new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix))->migrate();
    $httpDatabaseUser = 't19_enterprise_api';
    $httpDatabasePassword = 'test-only-t19-enterprise-api-db';
    $pdo->exec("DROP USER IF EXISTS `{$httpDatabaseUser}`@'127.0.0.1'");
    $pdo->exec("CREATE USER IF NOT EXISTS `{$httpDatabaseUser}`@'127.0.0.1' IDENTIFIED BY '{$httpDatabasePassword}'");
    $pdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO `{$httpDatabaseUser}`@'127.0.0.1'");
    $now = time();
    $createdAt = date('Y-m-d H:i:s', $now);

    $companyStatement = $pdo->prepare("INSERT INTO `{$prefix}companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (?,'normal',?,?)");
    $companyStatement->execute(['企业 A', $now, $now]); $companyA = (int) $pdo->lastInsertId();
    $companyStatement->execute(['企业 B', $now, $now]); $companyB = (int) $pdo->lastInsertId();
    $userStatement = $pdo->prepare("INSERT INTO `{$prefix}user` (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,'company_owner','normal',?,?)");
    $userStatement->execute([$companyA, 't19-enterprise-a', password_hash('T19-Enterprise-A-2026', PASSWORD_DEFAULT), $now, $now]);
    $userStatement->execute([$companyB, 't19-enterprise-b', password_hash('T19-Enterprise-B-2026', PASSWORD_DEFAULT), $now, $now]);
    $pdo->prepare("INSERT INTO `{$prefix}company_profiles` (`company_id`,`brand`,`industry`,`products_json`,`website`,`introduction`,`contacts_ciphertext`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$companyA, 'Acme GEO', 'Software', '["GEO service"]', 'https://example.invalid', 'Acme GEO serves small businesses.', 'encrypted', $now, $now]);
    $pdo->prepare("INSERT INTO `{$prefix}keywords` (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,'normal',101,101,?,?)")
        ->execute([$companyA, 'GEO optimization', $now, $now]);
    $keywordId = (int) $pdo->lastInsertId();
    $questionStatement = $pdo->prepare("INSERT INTO `{$prefix}questions` (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $questionStatement->execute([$companyA, $keywordId, 'How does Acme GEO help?', hash('sha256', 'how does acme geo help?', true), 'ai', 'pending_review', 101, 101, $now, $now]);
    $questionId = (int) $pdo->lastInsertId();
    $questionStatement->execute([$companyA, $keywordId, 'What is Acme GEO?', hash('sha256', 'what is acme geo?', true), 'ai', 'normal', 101, 101, $now, $now]);
    $unavailableQuestionId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}knowledge_items` (`company_id`,`type`,`title`,`content`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,'text',?,?,'normal',101,101,?,?)")
        ->execute([$companyA, 'Verified fact', 'Acme GEO supports auditable answer evidence.', $now, $now]);
    $knowledgeId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}prompt_templates` (`company_id`,`logical_key`,`name`,`scene`,`version_no`,`content`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,'article',1,?,'normal',101,101,?,?)")
        ->execute([$companyA, str_repeat('a', 32), 'GEO prompt', 'Write with confirmed evidence.', $now, $now]);
    $promptId = (int) $pdo->lastInsertId();

    $answerRaw = json_encode([
        'answer' => 'Acme GEO serves small businesses and supports auditable answer evidence.',
        'recommendation_reason' => 'The verified service matches the question.',
        'facts' => [
            ['claim' => 'Acme GEO serves small businesses.', 'source_type' => 'profile', 'source_id' => 'introduction', 'evidence_quote' => 'Acme GEO serves small businesses.'],
            ['claim' => 'It supports auditable answer evidence.', 'source_type' => 'knowledge', 'source_id' => $knowledgeId, 'evidence_quote' => 'supports auditable answer evidence'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $baseEnvironment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => 'test-only-t19-enterprise-api-token-key',
        'GEO_COMPANY_CONTACT_KEY' => 'base64:' . base64_encode(random_bytes(32)),
        'GEO_DATABASE_HOSTNAME' => $host,
        'GEO_DATABASE_HOSTPORT' => (string) $port,
        'GEO_DATABASE_DATABASE' => $database,
        'GEO_DATABASE_USERNAME' => $httpDatabaseUser,
        'GEO_DATABASE_PASSWORD' => $httpDatabasePassword,
        'GEO_DATABASE_PREFIX' => $prefix,
        'GEO_AI_PROVIDER' => 'mock',
        'GEO_AI_MOCK_RAW' => $answerRaw,
        'GEO_VISIBILITY_DRIVER' => 'mock',
        'GEO_VISIBILITY_MOCK_RAW_ANSWER' => 'Acme GEO is explicitly recommended for this use case.',
        'GEO_VISIBILITY_MOCK_CITATIONS' => '[{"url":"https://evidence.invalid/acme"}]',
        'GEO_VISIBILITY_MOCK_EVIDENCE' => '{"query_mode":"test_fixture","explicit_recommendation":true,"recommendation_reason":"Verified test evidence."}',
    ]);
    $server = t19EnterpriseApiStartServer($baseEnvironment);
    $base = (string) $server['base'];

    $unauthenticated = t19EnterpriseApiRequest($base, 'GET', '/api/geo_answer/detail?id=1');
    t19EnterpriseApiAssert(
        $unauthenticated['status'] === 401,
        'GEO answer API requires enterprise authentication status=' . $unauthenticated['status']
        . ' body=' . substr($unauthenticated['body'], 0, 500)
    );
    $loginA = t19EnterpriseApiRequest($base, 'POST', '/api/auth/login', ['account' => 't19-enterprise-a', 'password' => 'T19-Enterprise-A-2026']);
    $loginB = t19EnterpriseApiRequest($base, 'POST', '/api/auth/login', ['account' => 't19-enterprise-b', 'password' => 'T19-Enterprise-B-2026']);
    $tokenA = (string) ($loginA['payload']['data']['token'] ?? '');
    $tokenB = (string) ($loginB['payload']['data']['token'] ?? '');
    t19EnterpriseApiAssert($loginA['status'] === 200 && $loginB['status'] === 200 && $tokenA !== '' && $tokenB !== '', 'enterprise API tenant logins succeed');
    $headersA = ['Authorization: Bearer ' . $tokenA];
    $headersB = ['Authorization: Bearer ' . $tokenB];

    $review = t19EnterpriseApiRequest($base, 'POST', '/api/question/review', ['id' => $questionId, 'decision' => 'approved'], $headersA);
    t19EnterpriseApiAssert(
        $review['status'] === 200
        && ($review['payload']['data']['review_status'] ?? null) === 'approved'
        && ($review['payload']['data']['status'] ?? null) === 'normal',
        'AI question review exposes approved decision and stored normal status'
    );
    $repeatReview = t19EnterpriseApiRequest($base, 'POST', '/api/question/review', ['id' => $questionId, 'decision' => 'approved'], $headersA);
    t19EnterpriseApiAssert($repeatReview['status'] === 200, 'repeating the same AI question review is idempotent');
    $conflictingReview = t19EnterpriseApiRequest($base, 'POST', '/api/question/review', ['id' => $questionId, 'decision' => 'rejected'], $headersA);
    t19EnterpriseApiAssert($conflictingReview['status'] === 409, 'conflicting AI question review is rejected');
    $crossTenantReview = t19EnterpriseApiRequest($base, 'POST', '/api/question/review', ['id' => $questionId, 'decision' => 'approved'], $headersB);
    t19EnterpriseApiAssert($crossTenantReview['status'] === 404, 'another tenant cannot review an AI question');
    $invalidReview = t19EnterpriseApiRequest($base, 'POST', '/api/question/review', ['id' => $questionId, 'decision' => 'maybe'], $headersA);
    t19EnterpriseApiAssert($invalidReview['status'] === 422, 'unsupported AI question review decision is rejected');
    $legacyTerminalReview = t19EnterpriseApiRequest($base, 'POST', '/api/question/review', ['id' => $unavailableQuestionId, 'decision' => 'approved'], $headersA);
    t19EnterpriseApiAssert(
        $legacyTerminalReview['status'] === 409
        && $pdo->query("SELECT `reviewed_at` FROM `{$prefix}questions` WHERE `id`={$unavailableQuestionId}")->fetchColumn() === null,
        'legacy status changes without review metadata cannot masquerade as an approved review'
    );

    $generated = t19EnterpriseApiRequest($base, 'POST', '/api/geo_answer/generate', [
        'question_id' => $questionId, 'knowledge_item_ids' => [$knowledgeId],
    ], $headersA);
    $generatedId = (int) ($generated['payload']['data']['id'] ?? 0);
    t19EnterpriseApiAssert(
        $generated['status'] === 200 && $generatedId > 0
        && ($generated['payload']['data']['status'] ?? null) === 'pending_review'
        && count((array) ($generated['payload']['data']['facts'] ?? [])) === 2,
        'GEO target answer generation returns versioned facts'
    );
    $detail = t19EnterpriseApiRequest($base, 'GET', '/api/geo_answer/detail?id=' . $generatedId, [], $headersA);
    t19EnterpriseApiAssert(
        $detail['status'] === 200
        && isset($detail['payload']['data']['evidence'])
        && ($detail['payload']['data']['version_no'] ?? null) === 1,
        'GEO answer detail returns evidence and version number'
    );
    $revised = t19EnterpriseApiRequest($base, 'POST', '/api/geo_answer/revise', [
        'id' => $generatedId,
        'answer' => 'Acme GEO supports auditable answer evidence for small businesses.',
        'recommendation_reason' => 'Human-confirmed wording.',
        'facts' => $generated['payload']['data']['facts'],
    ], $headersA);
    $revisedId = (int) ($revised['payload']['data']['id'] ?? 0);
    t19EnterpriseApiAssert($revised['status'] === 200 && $revisedId > $generatedId && ($revised['payload']['data']['version_no'] ?? null) === 2, 'GEO answer revision appends a version');
    $history = t19EnterpriseApiRequest($base, 'GET', '/api/geo_answer/history?question_id=' . $questionId . '&page=1&per_page=20', [], $headersA);
    t19EnterpriseApiAssert($history['status'] === 200 && ($history['payload']['data']['total'] ?? null) === 2 && count($history['payload']['data']['items'] ?? []) === 2, 'GEO answer history returns stable pagination');
    $confirmed = t19EnterpriseApiRequest($base, 'POST', '/api/geo_answer/confirm', ['id' => $revisedId], $headersA);
    t19EnterpriseApiAssert($confirmed['status'] === 200 && ($confirmed['payload']['data']['status'] ?? null) === 'confirmed', 'GEO answer confirmation succeeds');
    $repeatConfirm = t19EnterpriseApiRequest($base, 'POST', '/api/geo_answer/confirm', ['id' => $revisedId], $headersA);
    t19EnterpriseApiAssert($repeatConfirm['status'] === 200 && ($repeatConfirm['payload']['data']['id'] ?? null) === $revisedId, 'repeating GEO answer confirmation is idempotent');
    $crossTenantAnswer = t19EnterpriseApiRequest($base, 'GET', '/api/geo_answer/detail?id=' . $revisedId, [], $headersB);
    t19EnterpriseApiAssert($crossTenantAnswer['status'] === 404, 'another tenant cannot read a GEO answer');

    $aiTask = t19EnterpriseApiRequest($base, 'POST', '/api/ai_task/create', [
        'keyword_id' => $keywordId,
        'question_id' => $questionId,
        'prompt_template_id' => $promptId,
        'knowledge_item_ids' => [$knowledgeId],
        'geo_answer_version_id' => $revisedId,
    ], array_merge($headersA, ['Idempotency-Key: t19-enterprise-answer-task']));
    $aiTaskId = (int) ($aiTask['payload']['data']['id'] ?? 0);
    t19EnterpriseApiAssert($aiTask['status'] === 200 && $aiTaskId > 0, 'confirmed GEO answer enters existing AI article task API');
    $snapshot = json_decode((string) $pdo->query("SELECT `input_snapshot_json` FROM `{$prefix}geo_async_jobs` WHERE `id`={$aiTaskId}")->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
    t19EnterpriseApiAssert(($snapshot['geo_target_answer']['id'] ?? null) === $revisedId, 'AI task snapshots the confirmed GEO answer through HTTP');
    $repeatAiTask = t19EnterpriseApiRequest($base, 'POST', '/api/ai_task/create', [
        'keyword_id' => $keywordId,
        'question_id' => $questionId,
        'prompt_template_id' => $promptId,
        'knowledge_item_ids' => [$knowledgeId],
        'geo_answer_version_id' => $revisedId,
    ], array_merge($headersA, ['Idempotency-Key: t19-enterprise-answer-task']));
    t19EnterpriseApiAssert(($repeatAiTask['payload']['data']['id'] ?? null) === $aiTaskId, 'AI task API remains idempotent with a GEO answer version');
    $unconfirmedAiTask = t19EnterpriseApiRequest($base, 'POST', '/api/ai_task/create', [
        'keyword_id' => $keywordId,
        'question_id' => $questionId,
        'prompt_template_id' => $promptId,
        'knowledge_item_ids' => [$knowledgeId],
        'geo_answer_version_id' => $generatedId,
    ], array_merge($headersA, ['Idempotency-Key: t19-enterprise-unconfirmed-task']));
    t19EnterpriseApiAssert(in_array($unconfirmedAiTask['status'], [404, 409], true), 'unconfirmed GEO answer cannot enter article generation');

    $baseline = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/baseline', [
        'question_id' => $questionId, 'platform' => 'Mock AI', 'model_name' => 'mock-visibility-v1',
    ], $headersA);
    $baselineCheckId = (int) ($baseline['payload']['data']['check_id'] ?? 0);
    $baselineResultId = (int) ($baseline['payload']['data']['result']['id'] ?? 0);
    t19EnterpriseApiAssert(
        $baseline['status'] === 200 && $baselineCheckId > 0 && $baselineResultId > 0
        && ($baseline['payload']['data']['result']['status'] ?? null) === 'recommended',
        'Baseline API returns the existing recommended visibility state'
    );
    $repeatBaseline = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/baseline', [
        'question_id' => $questionId, 'platform' => 'Mock AI', 'model_name' => 'mock-visibility-v1',
    ], $headersA);
    t19EnterpriseApiAssert(($repeatBaseline['payload']['data']['check_id'] ?? null) === $baselineCheckId && (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_results` WHERE `check_id`={$baselineCheckId}")->fetchColumn() === 1, 'repeating Baseline does not create a duplicate result');
    $visibilityDetail = t19EnterpriseApiRequest($base, 'GET', '/api/geo_visibility/result?id=' . $baselineResultId, [], $headersA);
    t19EnterpriseApiAssert($visibilityDetail['status'] === 200 && ($visibilityDetail['payload']['data']['status'] ?? null) === 'recommended', 'visibility result detail exposes the three-state contract');
    $crossTenantVisibility = t19EnterpriseApiRequest($base, 'GET', '/api/geo_visibility/result?id=' . $baselineResultId, [], $headersB);
    t19EnterpriseApiAssert($crossTenantVisibility['status'] === 404, 'another tenant cannot read a visibility result');

    $articleStatement = $pdo->prepare("INSERT INTO `{$prefix}articles` (`company_id`,`keyword_id`,`question_id`,`title`,`current_version_id`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,NULL,'draft',101,101,?,?)");
    $articleStatement->execute([$companyA, $keywordId, $questionId, 'Published GEO article', $now, $now]);
    $articleId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}article_versions` (`company_id`,`article_id`,`version_no`,`title_snapshot`,`content`,`source`,`prompt_template_id`,`geo_answer_version_id`,`created_by`,`createtime`) VALUES (?,?,1,?,?,'ai',?,?,101,?)")
        ->execute([$companyA, $articleId, 'Published GEO article', 'Evidence-led content.', $promptId, $revisedId, $now]);
    $articleVersionId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE `{$prefix}articles` SET `current_version_id`=? WHERE `id`=?")->execute([$articleVersionId, $articleId]);
    $publishedAt = date('Y-m-d H:i:s', time() + 60);
    $pdo->prepare("INSERT INTO `{$prefix}publish_orders` (`order_no`,`company_id`,`article_id`,`article_version_id`,`status`,`total_amount`,`frozen_amount`,`idempotency_key`,`request_fingerprint`,`created_by`,`created_at`,`updated_at`) VALUES (?,?,?,?, 'published',0,0,?,?,101,?,?)")
        ->execute(['T19-ENTERPRISE-ORDER', $companyA, $articleId, $articleVersionId, 't19-enterprise-order', hash('sha256', 't19-enterprise-order'), $createdAt, $createdAt]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}order_status_logs` (`company_id`,`order_id`,`from_status`,`to_status`,`source`,`event_key`,`created_at`) VALUES (?,?, 'submitted','published','test',?,?)")
        ->execute([$companyA, $orderId, 't19-enterprise-published', $publishedAt]);

    $plan = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/create_plan', [
        'question_id' => $questionId,
        'baseline_result_id' => $baselineResultId,
        'platform' => 'Mock AI',
        'model_name' => 'mock-visibility-v1',
        'interval_days' => 3,
    ], $headersA);
    $planId = (int) ($plan['payload']['data']['id'] ?? 0);
    t19EnterpriseApiAssert(
        $plan['status'] === 200 && $planId > 0 && ($plan['payload']['data']['interval_days'] ?? null) === 3,
        '3-day visibility recheck plan is exposed status=' . $plan['status'] . ' body=' . substr($plan['body'], 0, 500)
    );
    $duplicatePlan = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/create_plan', [
        'question_id' => $questionId,
        'baseline_result_id' => $baselineResultId,
        'platform' => 'Mock AI',
        'model_name' => 'mock-visibility-v1',
        'interval_days' => 3,
    ], $headersA);
    t19EnterpriseApiAssert($duplicatePlan['status'] === 409, 'duplicate visibility plan is rejected without another row');
    $invalidPlan = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/update_plan', ['id' => $planId, 'interval_days' => 5], $headersA);
    t19EnterpriseApiAssert($invalidPlan['status'] === 422, 'visibility plan rejects intervals outside 3/4/7 days');
    $updatedPlan = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/update_plan', ['id' => $planId, 'interval_days' => 4], $headersA);
    $expectedUpdatedRun = (new DateTimeImmutable($publishedAt, new DateTimeZone('Asia/Shanghai')))
        ->modify('+4 days')->format('Y-m-d H:i:s');
    t19EnterpriseApiAssert(
        $updatedPlan['status'] === 200
        && ($updatedPlan['payload']['data']['interval_days'] ?? null) === 4
        && ($updatedPlan['payload']['data']['next_run_at'] ?? null) === $expectedUpdatedRun,
        'visibility plan interval update reschedules the next run from its publication anchor'
    );
    $plans = t19EnterpriseApiRequest($base, 'GET', '/api/geo_visibility/plans?question_id=' . $questionId . '&page=1&per_page=20', [], $headersA);
    t19EnterpriseApiAssert($plans['status'] === 200 && ($plans['payload']['data']['total'] ?? null) === 1, 'visibility plan list is available to the enterprise tenant');

    $recurringDetectedAt = date('Y-m-d H:i:s', time() + 120);
    $pdo->prepare("INSERT INTO `{$prefix}geo_visibility_checks` (`company_id`,`plan_id`,`question_id`,`question_snapshot`,`brand_snapshot`,`check_type`,`provider_code`,`platform`,`model_name`,`scheduled_at`,`executed_at`,`status`,`idempotency_key`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,'recurring','mock','Mock AI','mock-visibility-v1',?,?,'succeeded',?,?,?)")
        ->execute([$companyA, $planId, $questionId, 'How does Acme GEO help?', 'Acme GEO', $recurringDetectedAt, $recurringDetectedAt, hash('sha256', 'recurring-mentioned'), $recurringDetectedAt, $recurringDetectedAt]);
    $mentionedCheckId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}geo_visibility_results` (`company_id`,`check_id`,`question_id`,`question_snapshot`,`brand_snapshot`,`platform`,`model_name`,`detected_at`,`raw_answer`,`visibility_status`,`brand_mentioned`,`explicitly_recommended`,`recommendation_reason`,`citations_json`,`evidence_json`,`classifier_version`,`created_at`) VALUES (?,?,?,?,?,?,?,?,'Acme GEO is mentioned.','mentioned',1,0,NULL,'[]','{\"provider_code\":\"mock\",\"real_network_executed\":false}',?,?)")
        ->execute([$companyA, $mentionedCheckId, $questionId, 'How does Acme GEO help?', 'Acme GEO', 'Mock AI', 'mock-visibility-v1', $recurringDetectedAt, \app\common\geo\VisibilityService::CLASSIFIER_VERSION, $recurringDetectedAt]);
    $mentionedResultId = (int) $pdo->lastInsertId();
    $notVisibleDetectedAt = date('Y-m-d H:i:s', time() + 180);
    $pdo->prepare("INSERT INTO `{$prefix}geo_visibility_checks` (`company_id`,`question_id`,`question_snapshot`,`brand_snapshot`,`check_type`,`provider_code`,`platform`,`model_name`,`scheduled_at`,`executed_at`,`status`,`idempotency_key`,`created_at`,`updated_at`) VALUES (?,?,?,?,'initial','mock','Mock AI','mock-visibility-v1',?,?,'succeeded',?,?,?)")
        ->execute([$companyA, $questionId, 'How does Acme GEO help?', 'Acme GEO', $notVisibleDetectedAt, $notVisibleDetectedAt, hash('sha256', 'initial-not-visible'), $notVisibleDetectedAt, $notVisibleDetectedAt]);
    $notVisibleCheckId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}geo_visibility_results` (`company_id`,`check_id`,`question_id`,`question_snapshot`,`brand_snapshot`,`platform`,`model_name`,`detected_at`,`raw_answer`,`visibility_status`,`brand_mentioned`,`explicitly_recommended`,`recommendation_reason`,`citations_json`,`evidence_json`,`classifier_version`,`created_at`) VALUES (?,?,?,?,?,?,?,?,'No matching company.','not_visible',0,0,NULL,'[]','{\"provider_code\":\"mock\",\"real_network_executed\":false}',?,?)")
        ->execute([$companyA, $notVisibleCheckId, $questionId, 'How does Acme GEO help?', 'Acme GEO', 'Mock AI', 'mock-visibility-v1', $notVisibleDetectedAt, \app\common\geo\VisibilityService::CLASSIFIER_VERSION, $notVisibleDetectedAt]);

    $visibilityHistory = t19EnterpriseApiRequest($base, 'GET', '/api/geo_visibility/history?question_id=' . $questionId . '&page=1&per_page=20', [], $headersA);
    $statuses = array_column((array) ($visibilityHistory['payload']['data']['items'] ?? []), 'status');
    sort($statuses);
    t19EnterpriseApiAssert($visibilityHistory['status'] === 200 && $statuses === ['mentioned', 'not_visible', 'recommended'], 'visibility history exposes not_visible, mentioned, and recommended');
    $trend = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/trend', ['current_result_id' => $mentionedResultId], $headersA);
    $trendId = (int) ($trend['payload']['data']['id'] ?? 0);
    t19EnterpriseApiAssert($trend['status'] === 200 && $trendId > 0 && ($trend['payload']['data']['trend'] ?? null) === 'down', 'visibility trend compares a recurring result with its Baseline');
    $repeatTrend = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/trend', ['current_result_id' => $mentionedResultId], $headersA);
    t19EnterpriseApiAssert(($repeatTrend['payload']['data']['id'] ?? null) === $trendId, 'repeating visibility trend comparison is idempotent');

    $pdo->prepare("INSERT INTO `{$prefix}geo_optimization_suggestions` (`company_id`,`result_id`,`comparison_id`,`provider_code`,`model_name`,`provider_request_id`,`missed_question`,`knowledge_gaps_json`,`question_directions_json`,`answer_directions_json`,`content_directions_json`,`generation_basis_json`,`status`,`created_by`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'pending_review',101,?)")
        ->execute([$companyA, $mentionedResultId, $trendId, 'mock', 'mock-t19', 'mock-suggestion', 'How does Acme GEO help?', '["Add verified proof"]', '["Clarify user intent"]', '["Strengthen answer evidence"]', '["Publish evidence-led content"]', '{}', $recurringDetectedAt]);
    $suggestionId = (int) $pdo->lastInsertId();
    $suggestion = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/suggestion', ['result_id' => $mentionedResultId], $headersA);
    t19EnterpriseApiAssert($suggestion['status'] === 200 && ($suggestion['payload']['data']['id'] ?? null) === $suggestionId && count($suggestion['payload']['data']['knowledge_gaps'] ?? []) === 1, 'enterprise API exposes existing optimization suggestions');
    $repeatSuggestion = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/suggestion', ['result_id' => $mentionedResultId], $headersA);
    t19EnterpriseApiAssert(($repeatSuggestion['payload']['data']['id'] ?? null) === $suggestionId, 'repeating optimization suggestion request is idempotent');

    t19EnterpriseApiStopServer($server); $server = [];
    $unavailableEnvironment = array_merge($baseEnvironment, [
        'GEO_AI_PROVIDER' => 'unsupported',
        'GEO_VISIBILITY_DRIVER' => 'responses_web_search',
        'GEO_VISIBILITY_WEB_SEARCH_ENABLED' => 'false',
        'GEO_VISIBILITY_EXTERNAL_CODE' => 'ToolNotOpen',
    ]);
    $server = t19EnterpriseApiStartServer($unavailableEnvironment);
    $base = (string) $server['base'];
    $loginUnavailable = t19EnterpriseApiRequest($base, 'POST', '/api/auth/login', ['account' => 't19-enterprise-a', 'password' => 'T19-Enterprise-A-2026']);
    $headersUnavailable = ['Authorization: Bearer ' . (string) ($loginUnavailable['payload']['data']['token'] ?? '')];
    $blockedAnswer = t19EnterpriseApiRequest($base, 'POST', '/api/geo_answer/generate', [
        'question_id' => $unavailableQuestionId, 'knowledge_item_ids' => [$knowledgeId],
    ], $headersUnavailable);
    t19EnterpriseApiAssert(
        $blockedAnswer['status'] === 503
        && ($blockedAnswer['payload']['data']['error_category'] ?? null) === 'configuration_error',
        'unavailable answer Provider returns an explicit dependency state'
    );
    $blockedBaseline = t19EnterpriseApiRequest($base, 'POST', '/api/geo_visibility/baseline', [
        'question_id' => $unavailableQuestionId, 'platform' => 'Unavailable AI', 'model_name' => 'unavailable-v1',
    ], $headersUnavailable);
    $blockedBaselineData = (array) ($blockedBaseline['payload']['data'] ?? []);
    t19EnterpriseApiAssert(
        $blockedBaseline['status'] === 200
        && ($blockedBaselineData['status'] ?? null) === 'failed'
        && ($blockedBaselineData['error_category'] ?? null) === 'external_capability_unavailable'
        && ($blockedBaselineData['external_error_code'] ?? null) === 'ToolNotOpen'
        && array_key_exists('result', $blockedBaselineData)
        && $blockedBaselineData['result'] === null,
        'ToolNotOpen returns an explicit unavailable state without a visibility result'
    );
    t19EnterpriseApiAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_results` WHERE `question_id`={$unavailableQuestionId}")->fetchColumn() === 0,
        'ToolNotOpen stores no fabricated visibility result'
    );

    fwrite(STDOUT, "PASS: T19 enterprise API HTTP contract\n");
} catch (Throwable $exception) {
    if ($server !== [] && is_file((string) ($server['err'] ?? ''))) {
        fwrite(STDERR, "SERVER-ERROR:\n" . substr((string) file_get_contents((string) $server['err']), -4000) . "\n");
    }
    $failure = $exception;
} finally {
    if ($server !== []) { t19EnterpriseApiStopServer($server); }
    if ($httpDatabaseUser !== null) {
        try { $pdo->exec("DROP USER IF EXISTS `{$httpDatabaseUser}`@'127.0.0.1'"); } catch (Throwable $ignored) {}
    }
    try { t19EnterpriseApiCleanup($pdo, $prefix); } catch (Throwable $ignored) {}
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
