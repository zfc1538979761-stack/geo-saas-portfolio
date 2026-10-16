<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class Company extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $page = max(1, (int) $this->request->param('page', 1));
        $perPage = min(100, max(1, (int) $this->request->param('per_page', 20)));
        $search = trim((string) $this->request->param('search', ''));
        $this->view->assign('page', $page);
        $this->view->assign('per_page', $perPage);
        $this->view->assign('search', $search);
        $this->view->assign('result', $service->companies($page, $perPage, $search));
        return $this->view->fetch();
    }

    public function status()
    {
        $reason = $this->requireHighRisk();
        $companyId = (int) $this->request->post('company_id', 0);
        $status = (string) $this->request->post('status', '');
        $service = new InternalQueryService($this->pdo, $this->prefix);
        try {
            $service->setCompanyStatus($companyId, $status, $this->actorId(), $reason, $this->ip());
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1400);
        }
        return $this->jsonOk(['company_id' => $companyId, 'status' => $status]);
    }
}
