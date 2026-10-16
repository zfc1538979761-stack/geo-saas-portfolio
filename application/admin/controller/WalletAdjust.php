<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\billing\BillingException;
use app\common\billing\BillingServiceFactory;
use app\common\controller\Backend;
use think\Config;
use think\response\Json;

class WalletAdjust extends Backend
{
    protected $noNeedLogin = [];
    protected $noNeedRight = [];

    public function _initialize()
    {
        parent::_initialize();
    }

    public function index()
    {
        $this->checkPermission();
        return $this->view->fetch();
    }

    public function credit(): Json
    {
        return $this->handleAdjust('credit');
    }

    public function debit(): Json
    {
        return $this->handleAdjust('debit');
    }

    private function handleAdjust(string $direction): Json
    {
        $this->checkPermission();
        $this->checkToggle();

        if (!$this->request->isPost()) {
            return $this->err('Method not allowed.', 405);
        }

        $companyId = (int) $this->request->post('company_id', 0);
        $amount = (string) $this->request->post('amount', '');
        $reason = trim((string) $this->request->post('reason', ''));
        $idempotencyKey = trim((string) $this->request->post('idempotency_key', ''));

        if ($companyId < 1) {
            return $this->err('Invalid company_id.', 1400);
        }
        if ($reason === '') {
            return $this->err('Reason is required.', 1400);
        }
        if ($idempotencyKey === '') {
            $idempotencyKey = 'admin_' . $direction . '_' . $companyId . '_' . time() . '_' . bin2hex(random_bytes(8));
        }

        $actorId = (int) ($this->auth->id ?? 0);
        $ip = $this->request->ip();
        $ua = substr((string) ($this->request->header('User-Agent') ?? ''), 0, 500);

        try {
            $service = BillingServiceFactory::create();
            if ($direction === 'credit') {
                $result = $service->manualCredit($companyId, $amount, $actorId, $reason, $idempotencyKey, $ip);
            } else {
                $result = $service->manualDebit($companyId, $amount, $actorId, $reason, $idempotencyKey, $ip);
            }

            // Audit the admin action
            try {
                $pdo = \think\Db::connect()->getPdo();
                $prefix = Config::get('database.prefix') ?: 'fa_';
                $stmt = $pdo->prepare(
                    'INSERT INTO `' . $prefix . 'audit_logs`'
                    . ' (`actor_id`,`actor_type`,`company_id`,`action`,`target_type`,`target_id`,'
                    . '`ip_address`,`detail_json`,`created_at`)'
                    . ' VALUES (:aid,:aty,:cid,:act,:tty,:tid,:ip,:det,NOW())'
                );
                $stmt->execute([
                    'aid' => $actorId, 'aty' => 'admin', 'cid' => $companyId,
                    'act' => 'wallet_manual_' . $direction, 'tty' => 'wallet',
                    'tid' => (string) $result['id'], 'ip' => $ip,
                    'det' => json_encode([
                        'amount' => $amount,
                        'reason' => $reason,
                        'available_after' => $result['available_after'],
                        'frozen_after' => $result['frozen_after'],
                        'idempotency_key' => $idempotencyKey,
                        'user_agent' => $ua,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable $e) {
                // audit failure must not undo the adjustment
            }

            return $this->json([
                'status' => 'success',
                'entry_id' => $result['id'],
                'available_after' => $result['available_after'],
                'frozen_after' => $result['frozen_after'],
            ]);
        } catch (BillingException $e) {
            return $this->err($e->getMessage(), $e->getCode() ?: 1400);
        } catch (\Throwable $e) {
            return $this->err('Internal error: ' . $e->getMessage(), 1500);
        }
    }

    private function checkPermission(): void
    {
        if (!$this->auth->isLogin()) {
            $this->err('Please login.', 1401);
            exit;
        }
        $permission = Config::get('geo_billing.manual_adjust_permission') ?: 'wallet.adjust';
        if (!$this->auth->check($permission, $this->auth->id)) {
            $this->err('Permission denied: ' . $permission . ' is required.', 1403);
            exit;
        }
    }

    private function checkToggle(): void
    {
        $enabled = Config::get('geo_billing.manual_adjust_enabled') ?: false;
        if (!$enabled) {
            $this->err('Manual wallet adjustment is disabled.');
            exit;
        }
    }

    private function err(string $msg, int $code = 1400): Json
    {
        return $this->json(['code' => $code, 'msg' => $msg, 'time' => time(), 'data' => null]);
    }

    private function json(array $data): Json
    {
        return \think\Response::create(['code' => 200, 'msg' => 'ok', 'time' => time(), 'data' => $data], 'json');
    }
}
