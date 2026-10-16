<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\controller\Backend;
use app\common\internal\InternalAccessPolicy;
use app\common\internal\InternalAuditWriter;
use think\Db;
use think\response\Json;

abstract class Base extends Backend
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];
    protected $layout = '';

    protected $pdo;
    protected $prefix;
    protected $audit;

    public function _initialize()
    {
        parent::_initialize();
        $this->pdo = Db::connect()->getConnection()->getPdo();
        $this->prefix = (string) Db::getConfig('prefix');
        $this->audit = new InternalAuditWriter($this->pdo, $this->prefix);
        $this->requirePermission(InternalAccessPolicy::permission(
            (string) $this->request->controller(),
            (string) $this->request->action()
        ));
    }

    protected function requirePermission(string $permission): void
    {
        if (!$this->auth->check($permission, (int) $this->auth->id)) {
            $this->jsonErr('Permission denied: ' . $permission . ' is required.', 1403)->send();
            exit;
        }
    }

    protected function requireHighRisk(): string
    {
        if (!$this->request->isPost()) {
            $this->jsonErr('Method not allowed.', 1405)->send();
            exit;
        }
        $reason = trim((string) $this->request->post('reason', ''));
        if ($reason === '') {
            $this->jsonErr('Reason is required.', 1400)->send();
            exit;
        }
        if ((string) $this->request->post('confirm', '') !== '1') {
            $this->jsonErr('Confirmation is required.', 1400)->send();
            exit;
        }
        $this->enforceHighRiskRateLimit();
        return $reason;
    }

    private function enforceHighRiskRateLimit(): void
    {
        $config = (array) \think\Config::get('geo_monitor');
        if ($config === []) {
            $config = (array) require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_monitor.php';
        }
        $window = max(1, (int) ($config['thresholds']['high_risk_window_seconds'] ?? 60));
        $max = max(1, (int) ($config['thresholds']['high_risk_max_actions'] ?? 20));
        $since = date('Y-m-d H:i:s', time() - $window);
        $table = $this->prefix . 'audit_logs';
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->q($table)
                . " WHERE `actor_id`=:actor AND LEFT(`action`,9)='internal_' AND `created_at`>=:since"
            );
            $stmt->execute(['actor' => $this->actorId(), 'since' => $since]);
            if ((int) $stmt->fetchColumn() >= $max) {
                $this->jsonErr('High-risk operation rate limit exceeded.', 1429)->send();
                exit;
            }
        } catch (\Throwable $exception) {
            $this->jsonErr('High-risk audit rate limit is unavailable.', 1500)->send();
            exit;
        }
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new \RuntimeException('Internal SQL identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }

    protected function actorId(): int
    {
        return (int) ($this->auth->id ?? 0);
    }

    protected function ip(): string
    {
        return (string) $this->request->ip();
    }

    protected function jsonOk(array $data = []): Json
    {
        return \think\Response::create(['code' => 200, 'msg' => 'ok', 'time' => time(), 'data' => $data], 'json');
    }

    protected function jsonErr(string $msg, int $code = 1400): Json
    {
        return \think\Response::create(['code' => $code, 'msg' => $msg, 'time' => time(), 'data' => null], 'json');
    }
}
