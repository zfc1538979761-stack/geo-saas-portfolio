<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\billing\BillingServiceFactory;
use app\common\controller\EnterpriseApi;

class Wallet extends EnterpriseApi
{
    public function balance()
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $service = BillingServiceFactory::create();
        $wallet = $service->getWallet($companyId);
        if ($wallet === null) {
            $wallet = $service->ensureWallet($companyId);
        }

        $this->success('', [
            'company_id' => $wallet['company_id'],
            'currency' => $wallet['currency'],
            'available_balance' => self::fmt($wallet['available_balance']),
            'frozen_balance' => self::fmt($wallet['frozen_balance']),
        ]);
    }

    public function ledger()
    {
        $context = $this->context();
        $companyId = $context->companyId();

        $page = max(1, (int) $this->request->param('page', 1));
        $perPage = min(max(1, (int) $this->request->param('per_page', 20)), 100);
        $entryType = $this->request->param('entry_type', null);
        $bizType = $this->request->param('biz_type', null);

        $service = BillingServiceFactory::create();
        $result = $service->listLedger($companyId, $page, $perPage,
            $entryType !== '' && $entryType !== null ? (string) $entryType : null,
            $bizType !== '' && $bizType !== null ? (string) $bizType : null);

        $items = array_map(function (array $entry): array {
            return [
                'id' => $entry['id'],
                'currency' => $entry['currency'],
                'entry_type' => $entry['entry_type'],
                'biz_type' => $entry['biz_type'],
                'biz_id' => $entry['biz_id'],
                'amount' => self::fmt($entry['amount']),
                'available_delta' => self::fmt($entry['available_delta']),
                'frozen_delta' => self::fmt($entry['frozen_delta']),
                'available_after' => self::fmt($entry['available_after']),
                'frozen_after' => self::fmt($entry['frozen_after']),
                'created_at' => $entry['created_at'],
            ];
        }, $result['items']);

        $this->success('', [
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    private static function fmt($value): string
    {
        if (is_string($value)) {
            $parts = explode('.', $value);
            $intPart = $parts[0] ?? '0';
            $decPart = $parts[1] ?? '00';
            return $intPart . '.' . str_pad(substr($decPart, 0, 2), 2, '0', STR_PAD_RIGHT);
        }
        return number_format((float) $value, 2, '.', '');
    }
}
