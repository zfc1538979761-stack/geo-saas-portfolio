<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalConfigService;
use app\common\internal\InternalQueryService;

class AiConfig extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $status = (new InternalConfigService())->status('ai');
        $this->view->assign('providers', $service->aiProviders());
        $this->view->assign('status', $status);
        $this->view->assign('runtime', (array) config('geo_ai'));
        return $this->view->fetch();
    }

    public function replace()
    {
        $reason = $this->requireHighRisk();
        $apiKey = (string) $this->request->post('api_key', '');
        try {
            (new InternalConfigService())->replaceKey('ai', 'api_key', $apiKey);
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1400);
        }
        $this->audit->record($this->actorId(), 'internal_ai_key_replaced', null, 'ai_config', 'ark', $this->ip(), [
            'field' => 'api_key',
            'reason' => $reason,
        ]);
        return $this->jsonOk(['message' => 'AI API key replacement completed.']);
    }
}
