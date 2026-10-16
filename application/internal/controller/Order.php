<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class Order extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('result', $service->orders(
            max(1, (int) $this->request->param('page', 1)),
            min(100, max(1, (int) $this->request->param('per_page', 20)))
        ));
        return $this->view->fetch();
    }

    public function reprocess()
    {
        $reason = $this->requireHighRisk();
        $orderId = (int) $this->request->post('order_id', 0);
        $action = (string) $this->request->post('action', '');
        if ($orderId < 1) {
            return $this->jsonErr('Order ID is required.', 1400);
        }
        $service = new InternalQueryService($this->pdo, $this->prefix);
        try {
            $result = $service->reprocessOrder($orderId, $action, $this->actorId(), $reason, $this->ip());
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1500);
        }
        return $this->jsonOk($result);
    }
}
