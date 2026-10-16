<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\migration\MigrationManager;

final class MediaFavoriteFailure extends RuntimeException
{
}

function favAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new MediaFavoriteFailure($message);
    }
}

function favRequest(
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
        'payload' => json_decode(is_string($bodyText) ? $bodyText : '', true),
    ];
}

function favWaitForServer(string $baseUrl): void
{
    $deadline = microtime(true) + 10;
    do {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        if (@file_get_contents($baseUrl . '/api/version', false, $context) !== false) {
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new MediaFavoriteFailure('local HTTP server did not become ready');
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
    fwrite(STDERR, "FAIL: media favorite test database configuration is incomplete\n");
    exit(1);
}

$prefix = 'fav_http_' . substr(md5((string) mt_rand()), 0, 8) . '_';
$process = null;
$logOut = tempnam(sys_get_temp_dir(), 'geo-fav-out-');
$logErr = tempnam(sys_get_temp_dir(), 'geo-fav-err-');
$pass = 0;
$fail = 0;

function favRecord(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        ++$pass;
        fwrite(STDOUT, "PASS: " . $label . "\n");
    } else {
        ++$fail;
        fwrite(STDOUT, "FAIL: " . $label . "\n");
    }
}

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
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    $applied = $manager->migrate();
    favAssert(in_array('202608240028_create_media_favorites', $applied, true), 'media favorites migration applied');

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
    $companyStmt->execute(['name' => '收藏企业A', 'status' => 'normal', 'created' => $now, 'updated' => $now]);
    $companyA = (int) $pdo->lastInsertId();
    $companyStmt->execute(['name' => '收藏企业B', 'status' => 'normal', 'created' => $now, 'updated' => $now]);
    $companyB = (int) $pdo->lastInsertId();
    $userStmt->execute([
        'company_id' => $companyA,
        'username' => 'fav-owner-a',
        'password_hash' => password_hash('Fav-A-Password-2026', PASSWORD_DEFAULT),
        'role' => 'company_owner',
        'status' => 'normal',
        'created' => $now,
        'updated' => $now,
    ]);
    $userStmt->execute([
        'company_id' => $companyB,
        'username' => 'fav-owner-b',
        'password_hash' => password_hash('Fav-B-Password-2026', PASSWORD_DEFAULT),
        'role' => 'company_owner',
        'status' => 'normal',
        'created' => $now,
        'updated' => $now,
    ]);

    $channelId = (int) $pdo->query(
        'SELECT `id` FROM `' . $prefix . 'channels` WHERE `code`=\'official_media\' LIMIT 1'
    )->fetchColumn();
    $influencerChannelId = (int) $pdo->query(
        'SELECT `id` FROM `' . $prefix . 'channels` WHERE `code`=\'influencer\' LIMIT 1'
    )->fetchColumn();
    $supplierId = (int) $pdo->query(
        'SELECT `id` FROM `' . $prefix . 'suppliers` WHERE `code`=\'mock\' LIMIT 1'
    )->fetchColumn();
    $pdo->prepare('UPDATE `' . $prefix . 'channels` SET `supplier_confirmed`=1 WHERE `id`=?')->execute([$channelId]);
    $pdo->prepare('UPDATE `' . $prefix . 'channels` SET `supplier_confirmed`=1 WHERE `id`=?')->execute([$influencerChannelId]);

    $mediaStmt = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'media_resources`'
        . ' (`supplier_id`,`external_id`,`channel_id`,`name`,`cost_price`,`cost_currency`,`status`,`attributes_json`,`last_synced_at`,`created_at`,`updated_at`)'
        . ' VALUES (?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())'
    );
    $mediaStmt->execute([
        $supplierId,
        'fav-news',
        $channelId,
        '收藏新闻媒体',
        '10.000000',
        'CNY',
        'active',
        '{"price_unconfirmed":false,"source_catalog":"/media/type/1","region":"全国","contact_type":"均可"}',
    ]);
    $newsMediaId = (int) $pdo->lastInsertId();
    $mediaStmt->execute([
        $supplierId,
        'fav-self',
        $influencerChannelId,
        '收藏自媒体账号',
        '5.000000',
        'CNY',
        'active',
        '{"price_unconfirmed":false,"source_catalog":"/media/type/3","platform":"小红书","fans_range":"1万-5万"}',
    ]);
    $selfMediaId = (int) $pdo->lastInsertId();

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    favAssert($socket !== false, 'reserve local HTTP port');
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpPort = (int) substr(strrchr((string) $socketName, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $httpPort;
    $serverEnvironment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => $tokenKey,
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
    favAssert(is_resource($process), 'start local HTTP server');
    favWaitForServer($baseUrl);

    $loginA = favRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 'fav-owner-a',
        'password' => 'Fav-A-Password-2026',
    ]);
    $loginB = favRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 'fav-owner-b',
        'password' => 'Fav-B-Password-2026',
    ]);
    $tokenA = (string) ($loginA['payload']['data']['token'] ?? '');
    $tokenB = (string) ($loginB['payload']['data']['token'] ?? '');
    favAssert($tokenA !== '' && $tokenB !== '', 'both test companies login');
    $headersA = ['Authorization: Bearer ' . $tokenA];
    $headersB = ['Authorization: Bearer ' . $tokenB];

    $fav = favRequest($baseUrl, 'POST', '/api/media/favorite', ['media_id' => $newsMediaId], $headersA);
    favRecord(($fav['status'] ?? 0) === 200 && ($fav['payload']['data']['is_collect'] ?? false) === true, 'favorite news media succeeds');
    $favAgain = favRequest($baseUrl, 'POST', '/api/media/favorite', ['media_id' => $newsMediaId], $headersA);
    favRecord(($favAgain['status'] ?? 0) === 200 && ($favAgain['payload']['data']['is_collect'] ?? false) === true, 'duplicate favorite is idempotent');

    $favList = favRequest($baseUrl, 'GET', '/api/media/index?is_collect=1&page=1&per_page=20', [], $headersA);
    $favIds = array_map(static function (array $item): int {
        return (int) ($item['id'] ?? 0);
    }, (array) ($favList['payload']['data']['items'] ?? []));
    favRecord(
        ($favList['status'] ?? 0) === 200 && in_array($newsMediaId, $favIds, true),
        'favorite list contains media'
    );
    $detailA = favRequest($baseUrl, 'GET', '/api/media/detail?id=' . $newsMediaId, [], $headersA);
    favRecord(
        ($detailA['status'] ?? 0) === 200 && (int) ($detailA['payload']['data']['is_collect'] ?? 0) === 1,
        'favorite state persists in detail'
    );

    $favListB = favRequest($baseUrl, 'GET', '/api/media/index?is_collect=1&page=1&per_page=20', [], $headersB);
    $favIdsB = array_map(static function (array $item): int {
        return (int) ($item['id'] ?? 0);
    }, (array) ($favListB['payload']['data']['items'] ?? []));
    favRecord(
        ($favListB['status'] ?? 0) === 200 && !in_array($newsMediaId, $favIdsB, true),
        'company B favorite list is isolated'
    );
    $detailB = favRequest($baseUrl, 'GET', '/api/media/detail?id=' . $newsMediaId, [], $headersB);
    favRecord(
        ($detailB['status'] ?? 0) === 200 && (int) ($detailB['payload']['data']['is_collect'] ?? 1) === 0,
        'company B detail shows not collected'
    );

    $unfav = favRequest($baseUrl, 'POST', '/api/media/unfavorite', ['media_id' => $newsMediaId], $headersA);
    favRecord(($unfav['status'] ?? 0) === 200 && ($unfav['payload']['data']['is_collect'] ?? true) === false, 'unfavorite media succeeds');
    $unfavAgain = favRequest($baseUrl, 'POST', '/api/media/unfavorite', ['media_id' => $newsMediaId], $headersA);
    favRecord(($unfavAgain['status'] ?? 0) === 200 && ($unfavAgain['payload']['data']['is_collect'] ?? true) === false, 'duplicate unfavorite is idempotent');
    $favListAfter = favRequest($baseUrl, 'GET', '/api/media/index?is_collect=1&page=1&per_page=20', [], $headersA);
    $favIdsAfter = array_map(static function (array $item): int {
        return (int) ($item['id'] ?? 0);
    }, (array) ($favListAfter['payload']['data']['items'] ?? []));
    favRecord(
        ($favListAfter['status'] ?? 0) === 200 && !in_array($newsMediaId, $favIdsAfter, true),
        'favorite list drops media after unfavorite'
    );

    $refav = favRequest($baseUrl, 'POST', '/api/media/favorite', ['media_id' => $newsMediaId], $headersA);
    $detailRefav = favRequest($baseUrl, 'GET', '/api/media/detail?id=' . $newsMediaId, [], $headersA);
    favRecord(
        ($refav['status'] ?? 0) === 200
        && ($detailRefav['status'] ?? 0) === 200
        && (int) ($detailRefav['payload']['data']['is_collect'] ?? 0) === 1,
        'refavorite persists after refresh'
    );

    $favoriteNews = favRequest($baseUrl, 'GET', '/api/media/index?is_collect=1&page=1&per_page=20', [], $headersA);
    favRecord(
        ($favoriteNews['status'] ?? 0) === 200,
        'favorite tab query remains healthy'
    );

    fwrite(STDOUT, "Media Favorite: " . $pass . " PASS / " . $fail . " FAIL\n");
    if ($fail > 0) {
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}
