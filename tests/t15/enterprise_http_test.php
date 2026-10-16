<?php

declare(strict_types=1);

if (getenv('GEO_T15_HTTP_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T15 enterprise HTTP test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T15_HTTP_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T15_HTTP_TEST_PREFIX') !== 't15_http_'
) {
    fwrite(STDERR, "FAIL: T15 HTTP test safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\billing\BillingService;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;

final class T15HttpFailure extends RuntimeException
{
}

function t15Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T15HttpFailure($message);
    }
}

function t15HttpRequest(
    string $baseUrl,
    string $method,
    string $path,
    array $body = [],
    array $headers = []
): array {
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", array_merge([
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ], $headers)),
            'content' => $body === [] ? '' : http_build_query($body),
            'ignore_errors' => true,
            'timeout' => 8,
            'follow_location' => 0,
        ],
    ]);
    $bodyText = @file_get_contents($baseUrl . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }
    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => is_string($bodyText) ? $bodyText : '',
        'payload' => json_decode(is_string($bodyText) ? $bodyText : '', true),
    ];
}

function t15WaitForServer(string $baseUrl): void
{
    $deadline = microtime(true) + 10;
    do {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        if (@file_get_contents($baseUrl . '/api/version', false, $context) !== false) {
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new T15HttpFailure('local HTTP server did not become ready');
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$databaseConfig = is_array($environment) ? ($environment['database'] ?? []) : [];
$appConfig = is_array($environment) ? ($environment['app'] ?? []) : [];
$processValue = static function (string $key, $fallback) {
    $value = getenv($key);
    return $value === false ? $fallback : $value;
};
$database = (string) $processValue('GEO_DATABASE_DATABASE', $databaseConfig['database'] ?? '');
$host = (string) $processValue('GEO_DATABASE_HOSTNAME', $databaseConfig['hostname'] ?? '');
$port = (int) $processValue('GEO_DATABASE_HOSTPORT', $databaseConfig['hostport'] ?? 0);
$username = (string) $processValue('GEO_DATABASE_USERNAME', $databaseConfig['username'] ?? '');
$password = (string) $processValue('GEO_DATABASE_PASSWORD', $databaseConfig['password'] ?? '');
$tokenKey = (string) $processValue('GEO_APP_TOKEN_KEY', $appConfig['token_key'] ?? '');
if ($database === '' || $host === '' || $port < 1 || $username === '' || $tokenKey === '') {
    fwrite(STDERR, "FAIL: T15 HTTP database configuration is incomplete\n");
    exit(1);
}

$prefix = 't15_http_';
$pdo = null;
$process = null;
$pipes = [];
$failure = null;
$before = [];
$logOut = tempnam(sys_get_temp_dir(), 'geo-t15-http-out-');
$logErr = tempnam(sys_get_temp_dir(), 'geo-t15-http-err-');

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
    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    t15Assert(
        count($manager->migrateThrough('202608180022_t14_article_original_link')) === 22,
        'T15 HTTP fixture applies all current migrations'
    );

    $now = time();
    $companyStmt = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:name,:status,:created,:updated)'
    );
    $userStmt = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'user`'
        . ' (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:company_id,:username,:password_hash,:role,:status,:created,:updated)'
    );
    $companyStmt->execute([
        'name' => 'T15企业',
        'status' => 'normal',
        'created' => $now,
        'updated' => $now,
    ]);
    $companyId = (int) $pdo->lastInsertId();
    $userStmt->execute([
        'company_id' => $companyId,
        'username' => 't15-http-owner',
        'password_hash' => password_hash('T15-Http-Password-2026', PASSWORD_DEFAULT),
        'role' => 'company_owner',
        'status' => 'normal',
        'created' => $now,
        'updated' => $now,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    t15Assert($socket !== false, 'reserve local HTTP port');
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpPort = (int) substr(strrchr((string) $socketName, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $httpPort;
    $serverEnvironment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => 'test-only-t15-http-token-key-20260807',
        'GEO_COMPANY_CONTACT_KEY' => 'base64:' . base64_encode(random_bytes(32)),
        'GEO_DATABASE_TYPE' => 'mysql',
        'GEO_DATABASE_HOSTNAME' => $host,
        'GEO_DATABASE_HOSTPORT' => (string) $port,
        'GEO_DATABASE_DATABASE' => $database,
        'GEO_DATABASE_USERNAME' => $username,
        'GEO_DATABASE_PASSWORD' => $password,
        'GEO_DATABASE_PREFIX' => $prefix,
        'GEO_CONTENT_STORAGE_DRIVER' => 'mock',
    ]);
    $process = proc_open(
        [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $httpPort,
            '-t',
            ROOT_PATH . 'public',
            ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'router.php',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $logOut, 'a'],
            2 => ['file', $logErr, 'a'],
        ],
        $pipes,
        ROOT_PATH,
        $serverEnvironment,
        ['bypass_shell' => true]
    );
    t15Assert(is_resource($process), 'start local HTTP server');
    t15WaitForServer($baseUrl);

    $login = t15HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't15-http-owner',
        'password' => 'T15-Http-Password-2026',
    ]);
    t15Assert($login['status'] === 200, 'enterprise login succeeds');
    $token = (string) ($login['payload']['data']['token'] ?? '');
    $headers = ['Authorization: Bearer ' . $token];

    foreach (['/api/media/index', '/api/order', '/api/wallet/balance'] as $path) {
        t15Assert(t15HttpRequest($baseUrl, 'GET', $path)['status'] === 401, 'unauthenticated ' . $path);
    }
    t15Assert(t15HttpRequest($baseUrl, 'GET', '/api/order?page=1&per_page=20', [], $headers)['payload']['data']['total'] === 0, 'empty order list is rendered by backend');

    $keyword = t15HttpRequest($baseUrl, 'POST', '/api/keyword/create', ['keyword' => 'T15 关键词'], $headers);
    t15Assert($keyword['status'] === 200, 'keyword create succeeds');
    $keywordId = (int) ($keyword['payload']['data']['id'] ?? 0);
    $question = t15HttpRequest($baseUrl, 'POST', '/api/question/create', [
        'keyword_id' => $keywordId,
        'text' => 'T15 问题',
    ], $headers);
    t15Assert($question['status'] === 200, 'question create succeeds');
    $questionId = (int) ($question['payload']['data']['id'] ?? 0);
    $prompt = t15HttpRequest($baseUrl, 'POST', '/api/prompt/create', [
        'name' => 'T15 提示词',
        'scene' => 'article',
        'content' => '生成文章',
    ], $headers);
    t15Assert($prompt['status'] === 200, 'prompt create succeeds');
    $promptId = (int) ($prompt['payload']['data']['id'] ?? 0);
    $article = t15HttpRequest($baseUrl, 'POST', '/api/article/create', [
        'keyword_id' => $keywordId,
        'question_id' => $questionId,
        'prompt_template_id' => $promptId,
        'knowledge_item_ids' => '[]',
        'title' => 'T15 文章',
        'content' => '正文',
    ], $headers);
    t15Assert($article['status'] === 200, 'article create succeeds');
    $articleId = (int) ($article['payload']['data']['id'] ?? 0);
    $articleVersionId = (int) ($article['payload']['data']['current_version_id'] ?? 0);

    $channelId = (int) $pdo->query(
        'SELECT `id` FROM `' . $prefix . 'channels` WHERE `code`=\'official_media\' LIMIT 1'
    )->fetchColumn();
    $influencerChannelId = (int) $pdo->query(
        'SELECT `id` FROM `' . $prefix . 'channels` WHERE `code`=\'influencer\' LIMIT 1'
    )->fetchColumn();
    $b2bChannelId = (int) $pdo->query(
        'SELECT `id` FROM `' . $prefix . 'channels` WHERE `code`=\'b2b\' LIMIT 1'
    )->fetchColumn();
    $supplierId = (int) $pdo->query(
        'SELECT `id` FROM `' . $prefix . 'suppliers` WHERE `code`=\'mock\' LIMIT 1'
    )->fetchColumn();
    $pdo->prepare(
        'UPDATE `' . $prefix . 'channels` SET `supplier_confirmed`=1 WHERE `id`=?'
    )->execute([$channelId]);
    $pdo->prepare(
        'UPDATE `' . $prefix . 'channels` SET `supplier_confirmed`=1 WHERE `id`=?'
    )->execute([$influencerChannelId]);

    $mediaStmt = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'media_resources`'
        . ' (`supplier_id`,`external_id`,`channel_id`,`name`,`cost_price`,`cost_currency`,`status`,`attributes_json`,`last_synced_at`,`created_at`,`updated_at`)'
        . ' VALUES (?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())'
    );
    $mediaStmt->execute([$supplierId, 'ext-t15-official', $channelId, 'T15 官媒', '10.00', 'CNY', 'active', '{}']);
    $mediaId = (int) $pdo->lastInsertId();
    $mediaStmt->execute([$supplierId, 'ext-t15-b2b', $b2bChannelId, 'T15 B2B', '5.00', 'CNY', 'active', '{}']);
    $b2bMediaId = (int) $pdo->lastInsertId();
    $mediaStmt->execute([
        $supplierId,
        'ext-t15-influencer',
        $influencerChannelId,
        'T15 小红书',
        '5.000000',
        'CNY',
        'active',
        '{"price_unconfirmed":false,"platform":"小红书","source_catalog":"/media/type/3","price_source":"user_confirmed_marketPrice","price_unit":"CNY_元"}',
    ]);
    $influencerMediaId = (int) $pdo->lastInsertId();
    $mediaStmt->execute([
        $supplierId,
        'ext-t15-filter',
        $channelId,
        'T15 筛选媒体',
        '20.000000',
        'CNY',
        'active',
        '{"price_unconfirmed":false,"platform":"官网","source_catalog":"/media/type/1","region":"全国","media_category":"官方媒体","portal_type":"人民网","channel_type":"新闻资讯","inclusion":"网页","draft_speed":"48小时","industry":"新闻资讯","price_source":"user_confirmed_marketPrice","price_unit":"CNY_元"}',
    ]);
    $filterMediaId = (int) $pdo->lastInsertId();

    $media = t15HttpRequest($baseUrl, 'GET', '/api/media/index?page=1&per_page=20', [], $headers);
    t15Assert($media['status'] === 200, 'media list succeeds');
    t15Assert(count((array) ($media['payload']['data']['channels'] ?? [])) === 3, 'media list returns backend channels');
    $b2b = array_values(array_filter((array) ($media['payload']['data']['channels'] ?? []), static function (array $channel): bool {
        return $channel['code'] === 'b2b';
    }))[0] ?? [];
    t15Assert(($b2b['supplier_confirmed'] ?? true) === false, 'b2b remains unconfirmed');
    $officialItem = array_values(array_filter((array) ($media['payload']['data']['items'] ?? []), static function (array $item) use ($mediaId): bool {
        return (int) ($item['id'] ?? 0) === $mediaId;
    }))[0] ?? [];
    t15Assert(($officialItem['can_order'] ?? false) === true, 'confirmed official media can order');

    $influencerItem = array_values(array_filter((array) ($media['payload']['data']['items'] ?? []), static function (array $item) use ($influencerMediaId): bool {
        return (int) ($item['id'] ?? 0) === $influencerMediaId;
    }))[0] ?? [];
    t15Assert(($influencerItem['price_unconfirmed'] ?? true) === false, 'influencer media has confirmed price');
    t15Assert(($influencerItem['can_order'] ?? false) === true, 'confirmed influencer media can order');
    t15Assert(($influencerItem['sale_price'] ?? '') === '6.00', 'confirmed influencer media shows enterprise sale price');
    $influencerGroups = array_values(array_filter((array) ($media['payload']['data']['channel_groups'] ?? []), static function (array $group): bool {
        return ($group['code'] ?? '') === 'influencer';
    }))[0] ?? [];
    t15Assert(in_array('小红书', (array) ($influencerGroups['platforms'] ?? []), true), 'influencer platform list is derived from real media attributes');
    $platformFilter = t15HttpRequest(
        $baseUrl,
        'GET',
        '/api/media/index?channel_id=' . $influencerChannelId . '&platform=' . rawurlencode('小红书'),
        [],
        $headers
    );
    t15Assert($platformFilter['status'] === 200, 'platform filter succeeds');
    $platformFilterItem = array_values(array_filter((array) ($platformFilter['payload']['data']['items'] ?? []), static function (array $item) use ($influencerMediaId): bool {
        return (int) ($item['id'] ?? 0) === $influencerMediaId;
    }))[0] ?? [];
    t15Assert(($platformFilterItem['id'] ?? 0) === $influencerMediaId, 'platform filter returns matching influencer media');

    $sourceFilter = t15HttpRequest($baseUrl, 'GET', '/api/media/index?source_catalog=' . rawurlencode('/media/type/3'), [], $headers);
    t15Assert($sourceFilter['status'] === 200, 'source_catalog filter succeeds');
    $sourceFilterItem = array_values(array_filter((array) ($sourceFilter['payload']['data']['items'] ?? []), static function (array $item) use ($influencerMediaId): bool {
        return (int) ($item['id'] ?? 0) === $influencerMediaId;
    }))[0] ?? [];
    t15Assert(($sourceFilterItem['id'] ?? 0) === $influencerMediaId, 'source_catalog filter returns influencer media');

    $regionFilter = t15HttpRequest($baseUrl, 'GET', '/api/media/index?region=' . rawurlencode('全国'), [], $headers);
    t15Assert($regionFilter['status'] === 200, 'region filter succeeds');
    $regionFilterItem = array_values(array_filter((array) ($regionFilter['payload']['data']['items'] ?? []), static function (array $item) use ($filterMediaId): bool {
        return (int) ($item['id'] ?? 0) === $filterMediaId;
    }))[0] ?? [];
    t15Assert(($regionFilterItem['id'] ?? 0) === $filterMediaId, 'region filter returns matching media');
    t15Assert(($regionFilterItem['inclusion'] ?? '') === '网页', 'media list exposes inclusion field');
    t15Assert(($regionFilterItem['draft_speed'] ?? '') === '48小时', 'media list exposes draft_speed field');

    $exactFilter = t15HttpRequest($baseUrl, 'GET', '/api/media/index?keyword=' . rawurlencode('T15 筛选媒体') . '&exact=1', [], $headers);
    t15Assert($exactFilter['status'] === 200, 'exact search filter succeeds');
    t15Assert(count((array) ($exactFilter['payload']['data']['items'] ?? [])) === 1, 'exact search returns only the exact name');

    $filterOptions = t15HttpRequest($baseUrl, 'GET', '/api/media/filters', [], $headers);
    t15Assert($filterOptions['status'] === 200, 'media filter options succeeds');
    $optionValues = array_column((array) ($filterOptions['payload']['data']['options']['source_catalog'] ?? []), 'value');
    t15Assert(
        in_array('/media/type/1', $optionValues, true) && in_array('/media/type/3', $optionValues, true),
        'filter options include real source catalogs'
    );

    $priceFilter = t15HttpRequest($baseUrl, 'GET', '/api/media/index?start_price=4&end_price=6', [], $headers);
    t15Assert($priceFilter['status'] === 200, 'price filter succeeds');
    $priceItem = array_values(array_filter((array) ($priceFilter['payload']['data']['items'] ?? []), static function (array $item) use ($influencerMediaId): bool {
        return (int) ($item['id'] ?? 0) === $influencerMediaId;
    }))[0] ?? [];
    t15Assert(($priceItem['id'] ?? 0) === $influencerMediaId, 'price filter returns confirmed influencer media in range');

    $priceFilterHigh = t15HttpRequest($baseUrl, 'GET', '/api/media/index?start_price=11&end_price=13', [], $headers);
    t15Assert($priceFilterHigh['status'] === 200, 'high price filter succeeds');
    $officialPriceItem = array_values(array_filter((array) ($priceFilterHigh['payload']['data']['items'] ?? []), static function (array $item) use ($mediaId): bool {
        return (int) ($item['id'] ?? 0) === $mediaId;
    }))[0] ?? [];
    t15Assert(($officialPriceItem['id'] ?? 0) === $mediaId, 'price filter returns official media in range');

    $influencerPreview = t15HttpRequest($baseUrl, 'POST', '/api/order/preview', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$influencerMediaId],
    ], $headers);
    t15Assert($influencerPreview['status'] === 200, 'confirmed influencer preview succeeds');
    t15Assert(($influencerPreview['payload']['data']['total_amount'] ?? '') === '6.00', 'confirmed influencer preview price is authoritative');

    $b2bPreview = t15HttpRequest($baseUrl, 'POST', '/api/order/preview', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$b2bMediaId],
    ], $headers);
    t15Assert($b2bPreview['status'] === 400, 'unconfirmed b2b preview is rejected');
    t15Assert(strpos((string) ($b2bPreview['payload']['msg'] ?? ''), '暂未开通') !== false, 'unconfirmed message is user-safe');

    $auditLogger = new PdoAuditLogger($pdo, $prefix);
    $billing = new BillingService($pdo, $auditLogger, $prefix);
    $billing->ensureWallet($companyId);
    $billing->manualCredit($companyId, '100.00', $userId, 'test', 't15-seed-' . uniqid());

    $preview = t15HttpRequest($baseUrl, 'POST', '/api/order/preview', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$mediaId],
    ], $headers);
    t15Assert($preview['status'] === 200, 'confirmed order preview succeeds');
    t15Assert(($preview['payload']['data']['total_amount'] ?? '') === '12.00', 'backend price is authoritative');
    $priceFingerprint = (string) ($preview['payload']['data']['price_fingerprint'] ?? '');

    $created = t15HttpRequest($baseUrl, 'POST', '/api/order/create', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$mediaId],
        'price_fingerprint' => $priceFingerprint,
    ], array_merge($headers, ['Idempotency-Key: t15-order-key-1']));
    t15Assert(
        $created['status'] === 200,
        'confirmed order create succeeds status=' . $created['status']
        . ' msg=' . (string) ($created['payload']['msg'] ?? '')
        . ' body=' . $created['body']
    );
    $orderId = (int) ($created['payload']['data']['id'] ?? 0);
    t15Assert($orderId > 0, 'order returns positive id body=' . $created['body']);
    t15Assert(($created['payload']['data']['status'] ?? '') === 'queued', 'created order enters queued state body=' . $created['body']);

    $compatCreate = t15HttpRequest($baseUrl, 'POST', '/api/orders', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$mediaId],
        'price_fingerprint' => $priceFingerprint,
    ], array_merge($headers, ['Idempotency-Key: t15-order-key-compat']));
    t15Assert($compatCreate['status'] === 200, 'plural POST orders preserves create compatibility');

    $duplicate = t15HttpRequest($baseUrl, 'POST', '/api/order/create', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$mediaId],
        'price_fingerprint' => $priceFingerprint,
    ], array_merge($headers, ['Idempotency-Key: t15-order-key-1']));
    t15Assert((int) ($duplicate['payload']['data']['id'] ?? 0) === $orderId, 'duplicate order click is idempotent');

    $detail = t15HttpRequest($baseUrl, 'GET', '/api/order/detail?id=' . $orderId, [], $headers);
    t15Assert($detail['status'] === 200, 'order detail succeeds');
    t15Assert(($detail['payload']['data']['order']['status'] ?? '') === 'queued', 'order detail reflects queued status');
    t15Assert(!isset($detail['payload']['data']['order']['request_fingerprint']), 'order detail omits private request fields');

    $pdo->prepare('UPDATE `' . $prefix . 'media_resources` SET `status`=? WHERE `id`=?')->execute(['offline', $mediaId]);
    $offlinePreview = t15HttpRequest($baseUrl, 'POST', '/api/order/preview', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$mediaId],
    ], $headers);
    t15Assert($offlinePreview['status'] === 400, 'offline media cannot be ordered');

    $pdo->prepare('UPDATE `' . $prefix . 'media_resources` SET `status`=? WHERE `id`=?')->execute(['active', $mediaId]);
    $pdo->prepare('UPDATE `' . $prefix . 'wallets` SET `available_balance`=0.00 WHERE `company_id`=?')->execute([$companyId]);
    $poorPreview = t15HttpRequest($baseUrl, 'POST', '/api/order/preview', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$mediaId],
    ], $headers);
    $poorOrder = t15HttpRequest($baseUrl, 'POST', '/api/order/create', [
        'article_id' => $articleId,
        'article_version_id' => $articleVersionId,
        'media_ids' => [$mediaId],
        'price_fingerprint' => (string) ($poorPreview['payload']['data']['price_fingerprint'] ?? ''),
    ], array_merge($headers, ['Idempotency-Key: t15-order-low-balance']));
    t15Assert($poorPreview['status'] === 200, 'preview still works before freeze');
    t15Assert($poorOrder['status'] !== 200, 'insufficient balance order create fails');
    t15Assert(
        strpos((string) ($poorOrder['payload']['msg'] ?? ''), '下单失败') !== false
        || strpos((string) ($poorOrder['payload']['msg'] ?? ''), '余额') !== false,
        'insufficient balance returns user-safe Chinese message'
    );

    $pdo->prepare('DELETE FROM `' . $prefix . 'user_token` WHERE `user_id`=?')->execute([$userId]);
    t15Assert(t15HttpRequest($baseUrl, 'GET', '/api/wallet/balance', [], $headers)['status'] === 401, 'expired session is rejected');

    $page = t15HttpRequest($baseUrl, 'GET', '/index/enterprise/index');
    t15Assert($page['status'] === 200, 'enterprise page is available');
    t15Assert(strpos($page['body'], 'GEO 企业工作台') !== false, 'enterprise page contains title');
    t15Assert(stripos($page['body'], 'innerHTML') === false, 'enterprise page avoids innerHTML');
    t15Assert(
        count(array_filter($page['headers'], static function (string $header): bool {
            return stripos($header, 'Content-Security-Policy:') === 0;
        })) === 1,
        'enterprise page has CSP'
    );

    proc_terminate($process);
    proc_close($process);
    $process = null;
    $logs = (string) @file_get_contents($logOut) . (string) @file_get_contents($logErr);
    t15Assert(stripos($logs, $token) === false, 'server logs omit enterprise token');

    fwrite(STDOUT, "PASS: T15 enterprise auth, media availability, order flow, empty data, offline media, duplicate click, insufficient balance, expiry, and page CSP\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($pdo instanceof PDO) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $prefixLike = addcslashes($prefix, '_%');
        $tables = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \'' . $prefixLike . '%\''
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $after = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($before !== [] && $after !== $before) {
            $failure = new T15HttpFailure('T15 HTTP cleanup changed unrelated tables');
        }
    }
    if (is_string($logOut)) {
        @unlink($logOut);
    }
    if (is_string($logErr)) {
        @unlink($logErr);
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
