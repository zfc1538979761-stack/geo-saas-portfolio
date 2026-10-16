<?php

declare(strict_types=1);

namespace app\common\publishing;

use app\common\job\AbstractQueueJob;
use app\common\billing\BillingService;
use app\common\supplier\SupplierAdapterFactory;
use app\common\tenant\PdoAuditLogger;
use PDO;

final class PublishSyncJob extends AbstractQueueJob
{
    /**
     * Run sync for a single order inline (without Redis/worker).
     */
    public static function runInline(PDO $pdo, array $payload, string $prefix): array
    {
        $companyId = (int) ($payload["company_id"] ?? 0);
        $orderId = (int) ($payload["order_id"] ?? 0);

        if ($companyId <= 0 || $orderId <= 0) {
            throw new \RuntimeException("PublishSyncJob: invalid payload.");
        }

        $supplierConfig = self::loadSupplierConfig();
        $adapter = SupplierAdapterFactory::create($supplierConfig);

        $audit = new PdoAuditLogger($pdo, $prefix);
        $billing = new BillingService($pdo, $audit, $prefix);
        $orderService = new PublishOrderService($pdo, $billing, $prefix);
        $syncService = new PublishSyncService($pdo, $orderService, $prefix);

        return $syncService->syncOrder($adapter, $companyId, $orderId);
    }

    /**
     * Run batch sync for all pending orders.
     *
     * @return array{total: int, results: array}
     */
    public static function runBatch(PDO $pdo, string $prefix, int $limit = 50): array
    {
        $supplierConfig = self::loadSupplierConfig();
        $adapter = SupplierAdapterFactory::create($supplierConfig);

        $audit = new PdoAuditLogger($pdo, $prefix);
        $billing = new BillingService($pdo, $audit, $prefix);
        $orderService = new PublishOrderService($pdo, $billing, $prefix);
        $syncService = new PublishSyncService($pdo, $orderService, $prefix);

        $pendingOrders = $syncService->findOrdersNeedingSync($limit);
        $results = [];

        foreach ($pendingOrders as $order) {
            try {
                $result = $syncService->syncOrder(
                    $adapter,
                    $order["company_id"],
                    $order["order_id"]
                );
                $results[] = [
                    "company_id" => $order["company_id"],
                    "order_id" => $order["order_id"],
                    "status" => $result["status"],
                    "synced" => $result["synced"],
                    "unchanged" => $result["unchanged"],
                    "failed" => $result["failed"],
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    "company_id" => $order["company_id"],
                    "order_id" => $order["order_id"],
                    "status" => "error",
                    "synced" => 0,
                    "unchanged" => 0,
                    "failed" => 0,
                    "message" => $e->getMessage(),
                ];
            }
        }

        return [
            "total" => count($pendingOrders),
            "results" => $results,
        ];
    }

    protected function handle(array $payload): void
    {
        $pdo = self::getPdo();
        $prefix = self::loadPrefix();
        self::runInline($pdo, $payload, $prefix);
    }

    private static function getPdo(): PDO
    {
        return PublishRuntimeConfig::requirePdo();
    }

    private static function loadPrefix(): string
    {
        return PublishRuntimeConfig::prefix();
    }

    private static function loadSupplierConfig(): array
    {
        return PublishRuntimeConfig::supplier();
    }
}
