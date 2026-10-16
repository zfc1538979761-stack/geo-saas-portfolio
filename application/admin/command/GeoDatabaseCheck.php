<?php

declare(strict_types=1);

namespace app\admin\command;

use PDO;
use Throwable;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;

final class GeoDatabaseCheck extends Command
{
    protected function configure()
    {
        $this->setName('geo:db:check')
            ->setDescription('Verify the configured database without exposing credentials');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            $pdo = Db::connect()->connect();
            if ((int) $pdo->query('SELECT 1')->fetchColumn() !== 1) {
                throw new \RuntimeException('Database probe returned an unexpected result.');
            }

            $database = (array) Config::get('database');
            $output->info(
                'PASS: database connection available'
                . ' driver=' . $this->safeLabel((string) ($database['type'] ?? 'configured'))
                . ' host=' . $this->safeHost((string) ($database['hostname'] ?? 'configured'))
                . ' port=' . $this->safePort($database['hostport'] ?? null)
                . ' username=' . (
                    trim((string) ($database['username'] ?? '')) === '' ? 'missing' : 'configured'
                )
                . ' authentication=' . $this->authenticationPlugin($pdo)
            );
            return 0;
        } catch (Throwable $exception) {
            $output->error(
                'FAIL: database connection unavailable'
                . ' class=' . str_replace('\\', '.', get_class($exception))
                . ' code=' . (string) $exception->getCode()
            );
            return 1;
        }
    }

    private function authenticationPlugin(PDO $pdo): string
    {
        try {
            $row = $pdo->query('SHOW CREATE USER CURRENT_USER()')->fetch(PDO::FETCH_NUM);
            $statement = is_array($row) ? implode(' ', array_map('strval', $row)) : '';
            if (preg_match('/IDENTIFIED WITH [`\'"]?([A-Za-z0-9_]+)/', $statement, $match) === 1) {
                return $this->safeLabel($match[1]);
            }
        } catch (Throwable $exception) {
            // Some least-privilege accounts cannot inspect their own CREATE USER statement.
        }

        $plugin = $pdo->query('SELECT @@default_authentication_plugin')->fetchColumn();
        return $this->safeLabel((string) $plugin);
    }

    private function safeHost(string $host): string
    {
        return preg_match('/^[A-Za-z0-9.:-]+$/', $host) === 1 ? $host : 'configured';
    }

    private function safePort($port): string
    {
        $value = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        return $value === false ? 'configured' : (string) $value;
    }

    private function safeLabel(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 ? $value : 'configured';
    }
}
