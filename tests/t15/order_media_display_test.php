<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\billing\BillingService;
use app\common\publishing\PublishOrderService;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;

final class T15OrderMediaDisplayAuditLogger implements AuditLogger
{
    public function record(
        string $action,
        ?TenantContext $context,
        string $targetType,
        ?string $targetId,
        string $ip,
        array $detail = []
    ): void {
    }
}

function t15OrderMediaDisplayAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = $environment['database'] ?? [];
$pdo = new PDO(
    'mysql:host=' . ($database['hostname'] ?? '127.0.0.1')
    . ';port=' . ($database['hostport'] ?? '3306')
    . ';dbname=' . ($database['database'] ?? '')
    . ';charset=utf8mb4',
    (string) ($database['username'] ?? ''),
    (string) ($database['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$prefix = 't15omd_';
$pdo->exec(
    'CREATE TEMPORARY TABLE `' . $prefix . 'publish_orders` ('
    . '`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
    . '`order_no` VARCHAR(64) NOT NULL,'
    . '`company_id` BIGINT UNSIGNED NOT NULL,'
    . '`article_id` BIGINT UNSIGNED NOT NULL,'
    . '`article_version_id` BIGINT UNSIGNED NOT NULL,'
    . '`status` VARCHAR(30) NOT NULL,'
    . '`total_amount` DECIMAL(18,2) NOT NULL,'
    . '`frozen_amount` DECIMAL(18,2) NOT NULL,'
    . '`created_at` DATETIME NOT NULL,'
    . '`updated_at` DATETIME NOT NULL'
    . ')'
);
$pdo->exec(
    'CREATE TEMPORARY TABLE `' . $prefix . 'publish_order_items` ('
    . '`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
    . '`company_id` BIGINT UNSIGNED NOT NULL,'
    . '`order_id` BIGINT UNSIGNED NOT NULL,'
    . '`media_id` BIGINT UNSIGNED NOT NULL,'
    . '`sale_snapshot` DECIMAL(18,2) NOT NULL,'
    . '`status` VARCHAR(30) NOT NULL,'
    . '`supplier_order_no` VARCHAR(100) DEFAULT NULL,'
    . '`published_url` VARCHAR(2048) DEFAULT NULL,'
    . '`created_at` DATETIME NOT NULL,'
    . '`updated_at` DATETIME NOT NULL'
    . ')'
);
$pdo->exec(
    'CREATE TEMPORARY TABLE `' . $prefix . 'media_resources` ('
    . '`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
    . '`name` VARCHAR(191) DEFAULT NULL,'
    . '`external_id` VARCHAR(191) NOT NULL,'
    . '`attributes_json` JSON DEFAULT NULL'
    . ')'
);
$pdo->exec(
    'CREATE TEMPORARY TABLE `' . $prefix . 'order_status_logs` ('
    . '`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
    . '`company_id` BIGINT UNSIGNED NOT NULL,'
    . '`order_id` BIGINT UNSIGNED NOT NULL,'
    . '`order_item_id` BIGINT UNSIGNED DEFAULT NULL,'
    . '`from_status` VARCHAR(30) DEFAULT NULL,'
    . '`to_status` VARCHAR(30) NOT NULL,'
    . '`source` VARCHAR(30) NOT NULL,'
    . '`reason` VARCHAR(500) DEFAULT NULL,'
    . '`created_at` DATETIME NOT NULL'
    . ')'
);

$pdo->exec(
    "INSERT INTO `{$prefix}publish_orders`"
    . " (`id`,`order_no`,`company_id`,`article_id`,`article_version_id`,`status`,`total_amount`,`frozen_amount`,`created_at`,`updated_at`)"
    . " VALUES (845,'PO-DISPLAY',7,11,12,'queued',36.00,36.00,'2026-08-25 12:00:00','2026-08-25 12:00:00')"
);
$mediaInsert = $pdo->prepare(
    "INSERT INTO `{$prefix}media_resources` (`id`,`name`,`external_id`,`attributes_json`) VALUES (?,?,?,?)"
);
$mediaInsert->execute([101, '测试新闻媒体', 'supplier-news-101', '{"source_catalog":"/media/type/1"}']);
$mediaInsert->execute([202, '测试小红书账号', 'supplier-self-202', '{"source_catalog":"/media/type/3","platform":"小红书"}']);
$mediaInsert->execute([303, '测试抖音账号', 'supplier-video-303', '{"source_catalog":"/video","platform":"抖音"}']);

$itemInsert = $pdo->prepare(
    "INSERT INTO `{$prefix}publish_order_items`"
    . " (`id`,`company_id`,`order_id`,`media_id`,`sale_snapshot`,`status`,`supplier_order_no`,`published_url`,`created_at`,`updated_at`)"
    . " VALUES (?,?,?,?,?,'queued',NULL,NULL,'2026-08-25 12:00:00','2026-08-25 12:00:00')"
);
$itemInsert->execute([1, 7, 845, 101, '12.00']);
$itemInsert->execute([2, 7, 845, 202, '12.00']);
$itemInsert->execute([3, 7, 845, 303, '12.00']);
$itemInsert->execute([4, 7, 845, 404, '0.00']);

$service = new PublishOrderService(
    $pdo,
    new BillingService($pdo, new T15OrderMediaDisplayAuditLogger(), $prefix),
    $prefix
);

$expectedDisplayNames = [
    '测试新闻媒体',
    '测试小红书账号',
    '测试抖音账号',
    '媒体 ID：404',
];
$expectedMediaIds = [101, 202, 303, 404];

$list = $service->listOrders(7, 1, 20);
t15OrderMediaDisplayAssert(count($list['items']) === 1, 'order list must contain the fixture order');
t15OrderMediaDisplayAssert(
    array_column($list['items'][0]['media_items'] ?? [], 'media_id') === $expectedMediaIds,
    'order list must preserve internal media IDs without changing supplier semantics'
);
t15OrderMediaDisplayAssert(
    array_column($list['items'][0]['media_items'] ?? [], 'media_display_name') === $expectedDisplayNames,
    'order list must prefer all three catalog names and use the explicit historical fallback'
);

$detail = $service->getDetail(7, 845, false);
t15OrderMediaDisplayAssert(
    array_column($detail['items'], 'media_id') === $expectedMediaIds,
    'order detail must preserve internal media IDs'
);
t15OrderMediaDisplayAssert(
    array_column($detail['items'], 'media_display_name') === $expectedDisplayNames,
    'order detail must use the same display names and fallback as the order list'
);
t15OrderMediaDisplayAssert(
    array_column($detail['items'], 'media_name') === ['测试新闻媒体', '测试小红书账号', '测试抖音账号', null],
    'unresolved history must not invent a media name'
);

fwrite(STDOUT, "PASS: T15 enterprise order media names and historical fallback\n");
