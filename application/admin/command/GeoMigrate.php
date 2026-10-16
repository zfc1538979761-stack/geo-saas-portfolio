<?php

declare(strict_types=1);

namespace app\admin\command;

use app\common\migration\MigrationManager;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;

final class GeoMigrate extends Command
{
    protected function configure()
    {
        $this->setName('geo:migrate')->setDescription('Run pending GEO foundation migrations');
    }

    protected function execute(Input $input, Output $output)
    {
        $completed = $this->manager()->migrate();
        $output->info($completed === [] ? 'No pending migrations.' : 'Applied: ' . implode(', ', $completed));
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
