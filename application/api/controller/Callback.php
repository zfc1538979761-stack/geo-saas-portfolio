<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\controller\Api;
use app\common\supplier\SupplierAdapterFactory;
use app\common\supplier\exception\SupplierConfigurationException;
use app\common\supplier\exception\SupplierUnsupportedCapabilityException;
use app\common\billing\BillingServiceFactory;
use app\common\publishing\PublishOrderService;
use app\common\publishing\PublishCallbackService;
use app\common\publishing\PublishSyncService;
use app\common\publishing\PublishNotificationService;
use app\common\tenant\PdoAuditLogger;

/**
 * T13: 供应商 webhook/callback 入口
 *
 * POST /api/callback
 * Body: {"supplier_code":"...", "event_id":"...", "company_id":..., ...}
 */
class Callback extends Api
{
    protected $noNeedLogin = ['receive'];
    protected $noNeedRight = ['*'];

    public function _initialize()
    {
        parent::_initialize();
    }

    /**
     * POST /api/callback
     *
     * Accepts supplier webhook callbacks (no authentication — signature-based).
     * Body is raw JSON containing at minimum:
     *   - supplier_code: supplier identifier
     *   - event_id: unique event identifier from supplier
     *   - company_id: tenant company ID
     */
    public function receive()
    {
        $rawBody = $this->request->getInput();
        if (!is_string($rawBody) || $rawBody === '') {
            $this->error('Empty body.', null, 400);
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $this->error('Invalid JSON.', null, 400);
        }

        $supplierCode = trim((string) ($body['supplier_code'] ?? ''));
        $eventId = trim((string) ($body['event_id'] ?? ''));
        $companyId = (int) ($body['company_id'] ?? 0);

        if ($supplierCode === '' || $eventId === '' || $companyId < 1) {
            $this->error('Missing required fields: supplier_code, event_id, company_id.', null, 400);
        }

        // Load adapter
        try {
            $config = \think\Config::get('geo_supplier');
            $adapter = SupplierAdapterFactory::create($config);
        } catch (SupplierConfigurationException $e) {
            $this->error('Supplier configuration error: ' . $e->getMessage(), null, 500);
            return;
        }

        // Verify supplier_code matches adapter
        if ($adapter->supplierCode() !== $supplierCode) {
            $this->error('Supplier code mismatch.', null, 400);
        }

        if (!$adapter->capabilities()['supports_callback']) {
            $this->error('Callback not supported for this supplier.', null, 501);
        }

        // Build services
        $prefix = \think\Config::get('database.prefix') ?: 'fa_';
        $pdo = \think\Db::connect()->getPdo();
        $auditLogger = new PdoAuditLogger($pdo, $prefix);
        $billing = BillingServiceFactory::create();
        $orderService = new PublishOrderService($pdo, $billing, $prefix);
        $syncService = new PublishSyncService($pdo, $orderService, $prefix);
        $callbackService = new PublishCallbackService($pdo, $syncService, $orderService, $prefix);

        try {
            $result = $callbackService->processCallback(
                $adapter,
                $companyId,
                $eventId,
                $rawBody
            );

            $status = $result['status'];
            if ($status === 'processed') {
                $this->success('Callback processed.', $result);
            } elseif ($status === 'duplicate') {
                $this->success('Duplicate event already processed.', $result);
            } elseif ($status === 'parse_failed') {
                $this->error($result['message'], null, 400);
            } elseif ($status === 'unmatched_order') {
                $this->error($result['message'], null, 404);
            } else {
                $this->error($result['message'], null, 500);
            }
        } catch (\Throwable $e) {
            $this->error('Callback processing failed: ' . $e->getMessage(), null, 500);
        }
    }
}
