<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class Webhook extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('result', $service->webhookEvents(
            max(1, (int) $this->request->param('page', 1)),
            min(100, max(1, (int) $this->request->param('per_page', 50)))
        ));
        return $this->view->fetch();
    }

    public function replay()
    {
        $reason = $this->requireHighRisk();
        $this->requirePermission('tenant.scope.bypass');
        $id = (int) $this->request->post('webhook_id', 0);
        if ($id < 1) {
            return $this->jsonErr('Webhook ID is required.', 1400);
        }
        $service = new InternalQueryService($this->pdo, $this->prefix);
        try {
            $result = $service->replayWebhookEvent($id, $this->actorId(), $reason, $this->ip());
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1500);
        }
        return $this->jsonOk($result);
    }
}
