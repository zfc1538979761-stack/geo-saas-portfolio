<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class Index extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('summary', $service->summary());
        return $this->view->fetch();
    }
}
