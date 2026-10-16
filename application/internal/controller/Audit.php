<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class Audit extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $companyId = (int) $this->request->param('company_id', 0);
        $this->view->assign('result', $service->auditLogs(
            max(1, (int) $this->request->param('page', 1)),
            min(100, max(1, (int) $this->request->param('per_page', 20))),
            $companyId > 0 ? $companyId : null
        ));
        return $this->view->fetch();
    }
}
