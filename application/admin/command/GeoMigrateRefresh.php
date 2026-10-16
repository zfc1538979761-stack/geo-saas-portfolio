<?php

declare(strict_types=1);

namespace app\admin\command;

use app\common\migration\MigrationManager;
use RuntimeException;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;
use think\Env;

final class GeoMigrateRefresh extends Command
{
    protected function configure()
    {
        $this->setName('geo:migrate:refresh')
            ->setDescription('Guarded rollback-all and re-migrate for disposable environments');
    }

    protected function execute(Input $input, Output $output)
    {
        $processEnvironment = getenv('GEO_APP_ENV');
        $environment = strtolower(
            $processEnvironment !== false && $processEnvironment !== ''
                ? (string) $processEnvironment
                : (string) Env::get('app.env', 'development')
        );
        if ($environment === 'production' || getenv('GEO_ALLOW_DESTRUCTIVE_MIGRATIONS') !== '1') {
            throw new RuntimeException(
                'Refresh is disabled. Use a non-production environment and set GEO_ALLOW_DESTRUCTIVE_MIGRATIONS=1.'
            );
        }

        $completed = $this->manager()->refresh();
        $output->info($completed === [] ? 'No migrations discovered.' : 'Refreshed: ' . implode(', ', $completed));
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
