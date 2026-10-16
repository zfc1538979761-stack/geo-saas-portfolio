<?php

declare(strict_types=1);

namespace app\admin\command;

use app\common\operations\MonitoringService;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;

final class GeoMonitorRun extends Command
{
    protected function configure()
    {
        $this->setName('geo:monitor:run')
            ->setDescription('Run the T17 health and alert check within the configured monitoring period.');
    }

    protected function execute(Input $input, Output $output)
    {
        $pdo = Db::connect()->connect();
        $database = (array) Config::get('database');
        $prefix = (string) ($database['prefix'] ?? 'fa_');
        if ($prefix === '') {
            $prefix = 'fa_';
        }
        $config = (array) Config::get('geo_monitor');
        $service = new MonitoringService($pdo, $prefix, $config);
        $result = $service->runCheck(true);
        $output->info(
            'PASS: monitor check completed status=' . $result['health']['overall_status']
            . ' alerts=' . count($result['alerts'])
            . ' snapshot_id=' . ($result['snapshot_id'] ?? 0)
        );
        return 0;
    }
}
