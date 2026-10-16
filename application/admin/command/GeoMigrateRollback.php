<?php

declare(strict_types=1);

namespace app\admin\command;

use app\common\migration\MigrationManager;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Db;

final class GeoMigrateRollback extends Command
{
    protected function configure()
    {
        $this->setName('geo:migrate:rollback')
            ->addOption('batches', 'b', Option::VALUE_REQUIRED, 'Number of latest batches to roll back', 1)
            ->setDescription('Roll back the latest GEO foundation migration batch');
    }

    protected function execute(Input $input, Output $output)
    {
        $rolledBack = $this->manager()->rollback((int) $input->getOption('batches'));
        $output->info(
            $rolledBack === [] ? 'No applied migrations.' : 'Rolled back: ' . implode(', ', $rolledBack)
        );
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
