<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\billing\BillingServiceFactory;
use app\common\internal\InternalQueryService;
use think\Config;

class Wallet extends Base
{
    public function index()
    {
        $service = new InternalQueryService($this->pdo, $this->prefix);
        $this->view->assign('result', $service->walletCompanies(
            max(1, (int) $this->request->param('page', 1)),
            min(100, max(1, (int) $this->request->param('per_page', 20)))
        ));
        $this->view->assign('manual_adjust_enabled', (bool) Config::get('geo_billing.manual_adjust_enabled'));
        return $this->view->fetch();
    }

    public function credit()
    {
        return $this->adjust('credit');
    }

    public function debit()
    {
        return $this->adjust('debit');
    }

    private function adjust(string $direction): \think\response\Json
    {
        $reason = $this->requireHighRisk();
        if (!(bool) Config::get('geo_billing.manual_adjust_enabled')) {
            return $this->jsonErr('Manual wallet adjustment is disabled.', 1400);
        }
        $companyId = (int) $this->request->post('company_id', 0);
        $amount = (string) $this->request->post('amount', '');
        $idempotencyKey = trim((string) $this->request->post('idempotency_key', ''));
        if ($companyId < 1 || $idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            return $this->jsonErr('Company ID and idempotency key are required.', 1400);
        }
        try {
            $service = BillingServiceFactory::create();
            if ($direction === 'credit') {
                $result = $service->manualCredit($companyId, $amount, $this->actorId(), $reason, $idempotencyKey, $this->ip());
            } else {
                $result = $service->manualDebit($companyId, $amount, $this->actorId(), $reason, $idempotencyKey, $this->ip());
            }
        } catch (\Throwable $e) {
            return $this->jsonErr($e->getMessage(), $e->getCode() ?: 1400);
        }
        $this->audit->record($this->actorId(), 'internal_wallet_manual_' . $direction, $companyId, 'wallet', (string) $result['id'], $this->ip(), [
            'amount' => $amount,
            'entry_id' => $result['id'],
            'available_after' => $result['available_after'],
            'frozen_after' => $result['frozen_after'],
            'reason' => $reason,
        ]);
        return $this->jsonOk($result);
    }
}
