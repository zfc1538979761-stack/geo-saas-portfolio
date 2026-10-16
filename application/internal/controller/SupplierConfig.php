<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\internal\InternalConfigService;

class SupplierConfig extends Base
{
    public function index()
    {
        $status = (new InternalConfigService())->status('supplier');
        $this->view->assign('status', $status);
        $this->view->assign('runtime', (array) config('geo_supplier'));
        return $this->view->fetch();
    }

    public function replace()
    {
        $reason = $this->requireHighRisk();
        $field = (string) $this->request->post('credential', '');
        $value = (string) $this->request->post('credential_value', '');
        try {
            (new InternalConfigService())->replaceKey('supplier', $field, $value);
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), 1400);
        }
        $this->audit->record($this->actorId(), 'internal_supplier_credential_replaced', null, 'supplier_config', 'media_market', $this->ip(), [
            'field' => $field,
            'reason' => $reason,
        ]);
        return $this->jsonOk(['message' => 'Supplier credential replacement completed.']);
    }
}
