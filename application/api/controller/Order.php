<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\controller\EnterpriseApi;
use app\common\publishing\PublishOrderFactory;
use app\common\publishing\PublishException;
use app\common\publishing\PublishSubmitDispatcher;
use app\common\publishing\PublishSubmitJob;
use app\common\publishing\PublishWorkerReadiness;
use app\common\supplier\ChannelAvailabilityService;
use think\Env;
use think\Queue;

class Order extends EnterpriseApi
{
    /**
     * POST /api/orders/preview
     */
    public function preview()
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $articleId = (int) $this->request->post("article_id", 0);
        $articleVersionId = (int) $this->request->post("article_version_id", 0);
        $mediaIds = $this->request->post("media_ids/a", []);

        if ($articleId < 1 || $articleVersionId < 1 || empty($mediaIds)) {
            $this->error("Missing required fields.", null, 400);
        }
        $mediaIds = array_map("intval", $mediaIds);
        $unavailable = ChannelAvailabilityService::create()->unavailableMediaIds($mediaIds);
        if ($unavailable !== []) {
            $this->error("所选媒体渠道暂未开通或已下架，不可下单。", null, 400);
        }

        try {
            $service = PublishOrderFactory::create();
            $result = $service->preview($companyId, $articleId, $articleVersionId, $mediaIds);
            $this->success("", $result);
        } catch (PublishException $e) {
            $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * POST /api/orders
     */
    public function create()
    {
        $context = $this->context();
        $companyId = $context->companyId();
        $userId = $context->actorId();

        $articleId = (int) $this->request->post("article_id", 0);
        $articleVersionId = (int) $this->request->post("article_version_id", 0);
        $mediaIds = $this->request->post("media_ids/a", []);
        $idempotencyKey = trim((string) $this->request->header("Idempotency-Key", ""));
        $priceFingerprint = trim((string) $this->request->post("price_fingerprint", ""));
        $originalLink = trim((string) $this->request->post("original_link", ""));

        if ($articleId < 1 || $articleVersionId < 1 || empty($mediaIds) || $idempotencyKey === "" || $priceFingerprint === "") {
            $this->error("Missing required fields.", null, 400);
        }
        if (strlen($idempotencyKey) > 191) {
            $this->error("Idempotency-Key too long.", null, 400);
        }

        $mediaIds = array_map("intval", $mediaIds);
        $unavailable = ChannelAvailabilityService::create()->unavailableMediaIds($mediaIds);
        if ($unavailable !== []) {
            $this->error("所选媒体渠道暂未开通或已下架，不可下单。", null, 400);
        }
        $ip = $this->request->ip();

        try {
            $service = PublishOrderFactory::create();
            $testing = strtolower(trim((string) getenv('GEO_APP_ENV'))) === 'testing';
            $dispatcher = null;
            if (!$testing) {
                $dispatcher = new PublishSubmitDispatcher(
                    $this->publishAutoSubmitEnabled(),
                    static function (array $payload, string $queue) {
                        return Queue::push(PublishSubmitJob::class, $payload, $queue);
                    },
                    static function (): bool {
                        return PublishWorkerReadiness::isReady(
                            PublishWorkerReadiness::defaultHeartbeatPath()
                        );
                    }
                );
                // Production must reject before the local order and wallet freeze are created.
                $dispatcher->assertReady();
            }

            $result = $service->create($companyId, $userId, $articleId, $articleVersionId,
                $mediaIds, $idempotencyKey, $priceFingerprint, $ip, $originalLink);
            $orderId = (int) ($result['id'] ?? 0);
            if ($orderId > 0 && ($result['status'] ?? '') === 'funds_frozen') {
                if ($testing) {
                    // T15's isolated HTTP contract intentionally has no external Redis Worker.
                    $service->queueForSubmit($companyId, $orderId);
                    $result['status'] = 'queued';
                    $result['submission_state'] = 'queued';
                } else {
                    $dispatch = $dispatcher->dispatch(
                        $companyId,
                        $orderId,
                        static function () use ($service, $companyId, $orderId): void {
                            $service->queueForSubmit($companyId, $orderId);
                        },
                        static function () use ($service, $companyId, $orderId): void {
                            $service->markSubmissionQueueFailed($companyId, $orderId);
                        }
                    );
                    $detail = $service->getDetail($companyId, $orderId, false);
                    $result = $detail['order'];
                    $result['submission_state'] = $dispatch['state'];
                    if ($dispatch['state'] === 'queue_failed') {
                        $result['submission_message'] = '订单已创建并冻结资金，但提交队列不可用，尚未发送供应商，请勿重复下单。';
                    }
                }
            }
            $this->success("", $result);
        } catch (PublishException $e) {
            $code = $e->getCode();
            if ($code === 1521) {
                $this->error("PRICE_CHANGED", null, 400);
            } elseif ($code === 1520) {
                $this->error("Idempotency conflict.", null, 409);
            } elseif ($code === 1544 || $code === 1547) {
                $this->error($e->getMessage(), null, 503);
            } else {
                $this->error($e->getMessage(), null, 400);
            }
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error("下单失败，请检查余额或稍后重试。", null, 400);
        }
    }

    private function publishAutoSubmitEnabled(): bool
    {
        $value = getenv('GEO_PUBLISH_AUTO_SUBMIT');
        if ($value === false) {
            $value = Env::get('publish.auto_submit', false);
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * GET /api/orders
     */
    public function index()
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $page = max(1, (int) $this->request->param("page", 1));
        $perPage = min(max(1, (int) $this->request->param("per_page", 20)), 100);
        $status = trim((string) $this->request->param("status", ""));

        $service = PublishOrderFactory::create();
        $result = $service->listOrders($companyId, $page, $perPage, $status !== "" ? $status : null);
        $this->success("", $result);
    }

    /**
     * GET /api/orders/{id}
     */
    public function detail($id = null)
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $orderId = (int) ($id ?? 0);
        if ($orderId < 1) {
            $this->error("Invalid order ID.", null, 400);
        }

        try {
            $service = PublishOrderFactory::create();
            $result = $service->getDetail($companyId, $orderId);
            $this->success("", $result);
        } catch (PublishException $e) {
            $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * POST /api/orders/{id}/cancel
     */
    public function cancel($id = null)
    {
        $context = $this->context();
        $companyId = $context->companyId();
        $userId = $context->actorId();
        $orderId = (int) ($id ?? 0);
        if ($orderId < 1) {
            $this->error("Invalid order ID.", null, 400);
        }

        $ip = $this->request->ip();

        try {
            $service = PublishOrderFactory::create();
            $result = $service->cancel($companyId, $orderId, $userId, $ip);
            $this->success("", $result);
        } catch (PublishException $e) {
            $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * GET /api/orders/notifications
     */
    public function notifications()
    {
        $context = $this->context();
        $companyId = $context->companyId();
        $page = max(1, (int) $this->request->param("page", 1));
        $perPage = min(max(1, (int) $this->request->param("per_page", 20)), 100);
        $unreadOnly = $this->request->param("unread", "") === "1" ? true : null;
        $notifService = \app\common\publishing\PublishNotificationService::create();
        $result = $notifService->listNotifications($companyId, $page, $perPage, $unreadOnly);
        $this->success("", $result);
    }

    /**
     * POST /api/orders/notifications/:id/read
     */
    public function markNotificationRead($id = null)
    {
        $context = $this->context();
        $companyId = $context->companyId();
        $notificationId = (int) ($id ?? 0);
        if ($notificationId < 1) { $this->error("Invalid notification ID.", null, 400); }
        $notifService = \app\common\publishing\PublishNotificationService::create();
        $ok = $notifService->markAsRead($companyId, $notificationId);
        $this->success("", ["marked" => $ok]);
    }
    /**
     * POST /api/orders/{id}/refund
     */
    public function refund($id = null)
    {
        $context = $this->context();
        $companyId = $context->companyId();
        $userId = $context->actorId();
        $orderId = (int) ($id ?? 0);
        if ($orderId < 1) {
            $this->error("Invalid order ID.", null, 400);
        }

        $reason = trim((string) $this->request->post("reason", ""));
        $ip = $this->request->ip();

        try {
            $service = \app\common\publishing\PublishRefundService::create();
            $result = $service->requestRefund($companyId, $orderId, $userId, $reason, $ip);
            $this->success("", $result);
        } catch (\app\common\publishing\PublishException $e) {
            $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * GET /api/orders/{id}/refunds
     */
    public function refunds($id = null)
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $orderId = (int) ($id ?? 0);
        if ($orderId < 1) {
            $this->error("Invalid order ID.", null, 400);
        }

        $page = max(1, (int) $this->request->param("page", 1));
        $perPage = min(max(1, (int) $this->request->param("per_page", 20)), 100);

        $service = \app\common\publishing\PublishRefundService::create();
        $result = $service->listRefunds($companyId, $orderId, $page, $perPage);
        $this->success("", $result);
    }

    /**
     * GET /api/orders/{orderId}/refund/{refundId}
     */
    public function refundDetail($orderId = null, $refundId = null)
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $refundId = (int) ($refundId ?? 0);
        if ($refundId < 1) {
            $this->error("Invalid refund ID.", null, 400);
        }

        try {
            $service = \app\common\publishing\PublishRefundService::create();
            $result = $service->getRefund($companyId, $refundId);
            $this->success("", $result);
        } catch (\app\common\publishing\PublishException $e) {
            $this->error($e->getMessage(), null, 404);
        }
    }
}
