<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class Job extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('result', $service->failedJobs(
            max(1, (int) $this->request->param('page', 1)),
            min(100, max(1, (int) $this->request->param('per_page', 20)))
        ));
        return $this->view->fetch();
    }

    public function replay()
    {
        $reason = $this->requireHighRisk();
        $this->requirePermission('tenant.scope.bypass');
        $jobId = (int) $this->request->post('job_id', 0);
        if ($jobId < 1) {
            return $this->jsonErr('Job ID is required.', 1400);
        }
        $service = new InternalQueryService($this->pdo, $this->prefix);
        try {
            $result = $service->replayJob($jobId, $this->actorId(), $reason, $this->ip());
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1500);
        }
        return $this->jsonOk($result);
    }
}
