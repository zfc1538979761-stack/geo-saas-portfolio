<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\internal\InternalQueryService;

function t16QueryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function t16QueryCreateTables(\PDO $pdo, string $prefix): void
{
    $pdo->exec(
        'CREATE TABLE `' . $prefix . 'companies` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`name` VARCHAR(191) NOT NULL,`status` VARCHAR(32) NOT NULL,'
        . '`createtime` INT UNSIGNED NULL,`updatetime` INT UNSIGNED NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE `' . $prefix . 'audit_logs` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`actor_id` BIGINT NOT NULL,`actor_type` VARCHAR(32) NOT NULL,'
        . '`company_id` BIGINT UNSIGNED NULL,`action` VARCHAR(100) NOT NULL,`target_type` VARCHAR(100) NOT NULL,'
        . '`target_id` VARCHAR(191) NULL,`ip_address` VARCHAR(45) NULL,`detail_json` TEXT NULL,'
        . '`created_at` DATETIME(6) NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE `' . $prefix . 'geo_async_jobs` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`company_id` BIGINT UNSIGNED NULL,`queue` VARCHAR(64) NOT NULL,'
        . '`job_type` VARCHAR(191) NOT NULL,`biz_id` VARCHAR(191) NULL,`status` VARCHAR(32) NOT NULL,'
        . '`attempts` INT UNSIGNED NOT NULL,`max_attempts` INT UNSIGNED NOT NULL,`last_error_code` VARCHAR(64) NULL,'
        . '`last_error_message` VARCHAR(255) NULL,`failed_at` DATETIME NULL,`created_at` DATETIME NOT NULL,'
        . '`updated_at` DATETIME NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE `' . $prefix . 'publish_orders` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`order_no` VARCHAR(191) NOT NULL,`company_id` BIGINT UNSIGNED NOT NULL,'
        . '`status` VARCHAR(32) NOT NULL,`total_amount` DECIMAL(18,2) NOT NULL,`frozen_amount` DECIMAL(18,2) NOT NULL,'
        . '`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE `' . $prefix . 'wallets` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`company_id` BIGINT UNSIGNED NOT NULL,`currency` CHAR(3) NOT NULL,'
        . '`available_balance` DECIMAL(18,2) NOT NULL,`frozen_balance` DECIMAL(18,2) NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE `' . $prefix . 'media_resources` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
}

function t16QueryDropTables(\PDO $pdo, string $prefix): void
{
    foreach (['media_resources', 'wallets', 'publish_orders', 'geo_async_jobs', 'audit_logs', 'companies'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
    }
}

$pdo = null;
$prefix = 't16_query_' . substr(hash('sha256', (string) getmypid()), 0, 8) . '_';
$drivers = \PDO::getAvailableDrivers();
if (in_array('sqlite', $drivers, true)) {
    $pdo = new \PDO('sqlite::memory:');
} else {
    $config = [];
    $section = 'global';
    foreach (file(ROOT_PATH . '.env') ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $line, $m) === 1) {
            $section = $m[1];
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $config[$section][trim($parts[0])] = trim($parts[1], " \t\"\r\n");
        }
    }
    $database = $config['database'] ?? $config['global'] ?? [];
    if (($database['hostname'] ?? '') === '' || ($database['database'] ?? '') === '' || ($database['username'] ?? '') === '') {
        fwrite(STDERR, "FAIL: T16 query test requires SQLite or a configured MySQL database\n");
        exit(1);
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $database['hostname'],
        (int) ($database['hostport'] ?? 3306),
        $database['database']
    );
    $pdo = new \PDO($dsn, $database['username'], $database['password'], [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

try {
    t16QueryCreateTables($pdo, $prefix);
    $companyStmt = $pdo->prepare('INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (:name,:status,:ct,:ut)');
    $companyStmt->execute(['name' => '测试企业', 'status' => 'normal', 'ct' => time(), 'ut' => time()]);
    $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO `' . $prefix . 'wallets` (`company_id`,`currency`,`available_balance`,`frozen_balance`) VALUES (:cid,:cur,:av,:fz)')
        ->execute(['cid' => $companyId, 'cur' => 'CNY', 'av' => '10.00', 'fz' => '2.00']);
    $pdo->prepare('INSERT INTO `' . $prefix . 'geo_async_jobs` (`company_id`,`queue`,`job_type`,`biz_id`,`status`,`attempts`,`max_attempts`,`last_error_code`,`last_error_message`,`created_at`,`updated_at`) VALUES (:cid,:q,:jt,:biz,:st,:a,:ma,:ec,:em,:ct,:ut)')
        ->execute(['cid' => $companyId, 'q' => 'geo_ai_generation', 'jt' => 'ai_article_generation', 'biz' => null, 'st' => 'failed', 'a' => 2, 'ma' => 3, 'ec' => 'rate_limited', 'em' => 'AI Provider rate limit was reached.', 'ct' => '2026-08-08 00:00:00', 'ut' => '2026-08-08 00:01:00']);
    $pdo->prepare('INSERT INTO `' . $prefix . 'publish_orders` (`order_no`,`company_id`,`status`,`total_amount`,`frozen_amount`,`created_at`,`updated_at`) VALUES (:no,:cid,:st,:ta,:fa,:ct,:ut)')
        ->execute(['no' => 'PO-T16', 'cid' => $companyId, 'st' => 'manual_review', 'ta' => '12.00', 'fa' => '12.00', 'ct' => '2026-08-08 00:00:00', 'ut' => '2026-08-08 00:02:00']);
    $pdo->prepare('INSERT INTO `' . $prefix . 'geo_async_jobs` (`company_id`,`queue`,`job_type`,`biz_id`,`status`,`attempts`,`max_attempts`,`last_error_code`,`last_error_message`,`created_at`,`updated_at`) VALUES (:cid,:q,:jt,:biz,:st,:a,:ma,:ec,:em,:ct,:ut)')
        ->execute(['cid' => $companyId, 'q' => 'unknown_queue', 'jt' => 'unknown_job', 'biz' => null, 'st' => 'failed', 'a' => 1, 'ma' => 1, 'ec' => 'unsupported', 'em' => 'Unsupported job', 'ct' => '2026-08-08 00:03:00', 'ut' => '2026-08-08 00:03:00']);

    $service = new InternalQueryService($pdo, $prefix);
    $service->setCompanyStatus($companyId, 'disabled', 42, 'T16 test', '127.0.0.1');
    $companies = $service->companies(1, 20, '测试');
    t16QueryAssert($companies['total'] === 1, 'Company search returns the fixture.');
    t16QueryAssert($companies['items'][0]['status'] === 'disabled', 'Company status change is persisted.');

    $audit = $service->auditLogs(1, 20);
    t16QueryAssert($audit['total'] >= 1, 'Company status change writes an audit log.');
    t16QueryAssert($audit['items'][0]['action'] === 'internal_company_status_changed', 'Audit action is traceable.');

    $failed = $service->failedJobs(1, 20);
    t16QueryAssert($failed['total'] === 2, 'Failed job list returns the fixture.');
    $rateLimitedFound = false;
    foreach ($failed['items'] as $failedJob) {
        if (($failedJob['last_error_code'] ?? '') === 'rate_limited') {
            $rateLimitedFound = true;
        }
    }
    t16QueryAssert($rateLimitedFound, 'Failed job error code is present.');

    $unsupportedReplay = false;
    try {
        $service->replayJob(2, 42, 'T16 replay guard', '127.0.0.1');
    } catch (RuntimeException $e) {
        $unsupportedReplay = stripos($e->getMessage(), 'cannot be replayed') !== false;
    }
    t16QueryAssert($unsupportedReplay, 'Unsupported failed job replay is rejected before execution.');

    $orders = $service->orders(1, 20);
    t16QueryAssert($orders['total'] === 1, 'Manual review order list returns the fixture.');
    t16QueryAssert($orders['items'][0]['company_name'] === '测试企业', 'Order list joins company name.');

    $wallets = $service->walletCompanies(1, 20);
    t16QueryAssert(count($wallets) === 1, 'Wallet list returns the fixture.');
    t16QueryAssert($wallets[0]['available_balance'] === '10.00', 'Wallet available balance is returned.');

    echo "PASS: T16 query service tests passed\n";
} finally {
    if ($pdo !== null) {
        t16QueryDropTables($pdo, $prefix);
    }
}
