<?php

declare(strict_types=1);

if (getenv('GEO_T05_CONCURRENCY_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T05 concurrency test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T05_CONCURRENCY_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T05_CONCURRENCY_TEST_PREFIX') !== 't05_concurrency_'
) {
    fwrite(STDERR, "FAIL: T05 concurrency safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T05ConcurrencyFailure extends RuntimeException
{
}

function t05ConcurrencyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T05ConcurrencyFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t05ConcurrentPair(array $environment, string $code): array
{
    $processes = [];
    $start = sprintf('%.6F', microtime(true) + 0.7);
    foreach ([1, 2] as $position) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT_PATH,
            array_merge(getenv(), $environment, ['GEO_T05_START_AT' => $start]),
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new T05ConcurrencyFailure('concurrency worker failed to start');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $err !== '') {
            throw new T05ConcurrencyFailure('concurrency worker failed without exposing credentials');
        }
        $results[] = $out;
    }
    sort($results);
    return $results;
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($environment) ? ($environment['database'] ?? []) : [];
$value = static function (string $key, $fallback) {
    $current = getenv($key);
    return $current === false ? $fallback : $current;
};
$host = (string) $value('GEO_DATABASE_HOSTNAME', $database['hostname'] ?? '');
$port = (int) $value('GEO_DATABASE_HOSTPORT', $database['hostport'] ?? 0);
$name = (string) $value('GEO_DATABASE_DATABASE', $database['database'] ?? '');
$user = (string) $value('GEO_DATABASE_USERNAME', $database['username'] ?? '');
$password = (string) $value('GEO_DATABASE_PASSWORD', $database['password'] ?? '');
$prefix = 't05_concurrency_';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$tables = array_map(static function (string $table) use ($prefix): string {
    return $prefix . $table;
}, ['questions', 'keywords', 'company_profiles', 'audit_logs', 'geo_auth_throttles', 'user_token', 'user', 'companies', 'geo_async_jobs', 'geo_migrations']);
$failure = null;

try {
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    t05ConcurrencyAssert(count($manager->migrateThrough('202608010005_create_keywords_and_questions')) === 5, 'concurrency fixture applies T01 through T05');
    $now = time();
    $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (\'Concurrent Company\',\'normal\',:created,:updated)'
    )->execute(['created' => $now, 'updated' => $now]);
    $companyId = (int) $pdo->lastInsertId();
    $workerEnvironment = [
        'GEO_T05_DSN' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        'GEO_T05_DB_USER' => $user,
        'GEO_T05_DB_PASSWORD' => $password,
        'GEO_T05_PREFIX' => $prefix,
        'GEO_T05_COMPANY_ID' => (string) $companyId,
        'GEO_T05_NOW' => (string) $now,
    ];
    $bootstrap = <<<'PHP'
$pdo=new PDO(getenv('GEO_T05_DSN'),getenv('GEO_T05_DB_USER'),getenv('GEO_T05_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
while(microtime(true)<(float)getenv('GEO_T05_START_AT')){usleep(1000);}
PHP;
    $keywordWorker = $bootstrap . <<<'PHP'
try{$s=$pdo->prepare('INSERT INTO `'.getenv('GEO_T05_PREFIX').'keywords` (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (:company,"Concurrent Keyword","normal",1,1,:created,:updated)');$s->execute(['company'=>(int)getenv('GEO_T05_COMPANY_ID'),'created'=>(int)getenv('GEO_T05_NOW'),'updated'=>(int)getenv('GEO_T05_NOW')]);echo 'created';}catch(PDOException $e){if($e->getCode()==='23000'&&(int)($e->errorInfo[1]??0)===1062){echo 'duplicate';}else{fwrite(STDERR,'database error');exit(2);}}
PHP;
    t05ConcurrencyAssert(
        t05ConcurrentPair($workerEnvironment, $keywordWorker) === ['created', 'duplicate'],
        'two simultaneous keyword creates yield one row and one duplicate'
    );
    $keywordId = (int) $pdo->query(
        'SELECT `id` FROM `' . $prefix . 'keywords` WHERE `company_id`=' . $companyId
    )->fetchColumn();
    $workerEnvironment['GEO_T05_KEYWORD_ID'] = (string) $keywordId;
    $questionWorker = $bootstrap . <<<'PHP'
$text='Concurrent Question';$hash=hash('sha256',strtolower($text),true);try{$s=$pdo->prepare('INSERT INTO `'.getenv('GEO_T05_PREFIX').'questions` (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (:company,:keyword,:text,:hash,"manual","normal",1,1,:created,:updated)');$s->execute(['company'=>(int)getenv('GEO_T05_COMPANY_ID'),'keyword'=>(int)getenv('GEO_T05_KEYWORD_ID'),'text'=>$text,'hash'=>$hash,'created'=>(int)getenv('GEO_T05_NOW'),'updated'=>(int)getenv('GEO_T05_NOW')]);echo 'created';}catch(PDOException $e){if($e->getCode()==='23000'&&(int)($e->errorInfo[1]??0)===1062){echo 'duplicate';}else{fwrite(STDERR,'database error');exit(2);}}
PHP;
    t05ConcurrencyAssert(
        t05ConcurrentPair($workerEnvironment, $questionWorker) === ['created', 'duplicate'],
        'two simultaneous question creates yield one row and one duplicate'
    );
    t05ConcurrencyAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'keywords`')->fetchColumn() === 1,
        'keyword concurrency leaves exactly one row'
    );
    t05ConcurrencyAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'questions`')->fetchColumn() === 1,
        'question concurrency leaves exactly one row'
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ': ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T05 true concurrent uniqueness tests passed\n");
