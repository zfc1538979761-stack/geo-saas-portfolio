<?php

declare(strict_types=1);

namespace app\admin\command;

use app\common\migration\MigrationManager;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;

final class GeoMigrateStatus extends Command
{
    protected function configure()
    {
        $this->setName('geo:migrate:status')->setDescription('Show GEO foundation migration status');
    }

    protected function execute(Input $input, Output $output)
    {
        foreach ($this->manager()->status() as $migration => $status) {
            $output->writeln($status . ' ' . $migration);
        }
        return 0;
    }

    private function manager(): MigrationManager
    {
        return new MigrationManager(
            Db::connect()->connect(),
            ROOT_PATH . 'database' . DS . 'migrations',
            (string) Config::get('database.prefix')
        );
    }
}
