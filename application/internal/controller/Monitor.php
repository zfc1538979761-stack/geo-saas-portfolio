<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class Monitor extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('health', $service->monitorHealth());
        $this->view->assign('alerts', $service->alertEvents(1, 50));
        return $this->view->fetch();
    }

    public function health()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        return $this->jsonOk($service->monitorHealth());
    }

    public function check()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $result = $service->runMonitorCheck($this->actorId(), $this->ip());
        return $this->jsonOk($result);
    }

    public function alerts()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('alerts', $service->alertEvents(
            max(1, (int) $this->request->param('page', 1)),
            min(100, max(1, (int) $this->request->param('per_page', 50)))
        ));
        return $this->view->fetch();
    }

    public function ack()
    {
        $reason = $this->requireHighRisk();
        $id = (int) $this->request->post('id', 0);
        if ($id < 1) {
            return $this->jsonErr('Alert ID is required.', 1400);
        }
        $service = new InternalQueryService($this->pdo, $this->prefix);
        try {
            $result = $service->acknowledgeAlert($id, $this->actorId(), $reason, $this->ip());
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1500);
        }
        return $this->jsonOk($result);
    }
}
