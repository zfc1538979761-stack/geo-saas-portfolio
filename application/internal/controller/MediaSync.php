<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class MediaSync extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('overview', $service->mediaSyncOverview());
        return $this->view->fetch();
    }

    public function trigger()
    {
        $reason = $this->requireHighRisk();
        $type = (string) $this->request->post('type', 'full');
        $service = new InternalQueryService($this->pdo, $this->prefix);
        try {
            $result = $service->triggerMediaSync($type, $this->actorId(), $reason, $this->ip());
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1500);
        }
        return $this->jsonOk($result);
    }
}
