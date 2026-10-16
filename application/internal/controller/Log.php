<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalQueryService;

class Log extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('files', $service->logFiles());
        return $this->view->fetch();
    }

    public function show()
    {
        $fileName = trim((string) $this->request->post('file', ''));
        $service = new InternalQueryService($this->pdo, $this->prefix);
        try {
            $this->view->assign('log', $service->logTail($fileName, 300));
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1400);
        }
        return $this->view->fetch('show');
    }
}
