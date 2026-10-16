<?php

declare(strict_types=1);

namespace app\common\publishing;

use RuntimeException;
use think\Config;

final class PublishRuntimeConfig
{
    public static function database(): array
    {
        $config = Config::get('database');
        if (!is_array($config) || ($config['hostname'] ?? '') === '' || ($config['database'] ?? '') === '') {
            $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
            $envFile = $root . '.env';
            if (is_file($envFile)) {
                $env = parse_ini_file($envFile, true, INI_SCANNER_RAW) ?: [];
                $db = $env['database'] ?? [];
                $config = [
                    'hostname' => (string) ($db['hostname'] ?? '127.0.0.1'),
                    'hostport' => (string) ($db['hostport'] ?? '3306'),
                    'database' => (string) ($db['database'] ?? ''),
                    'username' => (string) ($db['username'] ?? ''),
                    'password' => (string) ($db['password'] ?? ''),
                    'prefix' => (string) ($db['prefix'] ?? 'fa_'),
                ];
            }
        }
        return is_array($config) ? $config : [];
    }

    public static function prefix(): string
    {
        $config = self::database();
        return (string) ($config['prefix'] ?? 'fa_');
    }

    public static function supplier(): array
    {
        $config = Config::get('geo_supplier');
        if (!is_array($config) || $config === []) {
            $appPath = defined('APP_PATH') ? APP_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
            $file = $appPath . 'extra' . DIRECTORY_SEPARATOR . 'geo_supplier.php';
            if (is_file($file)) {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $config = $loaded;
                }
            }
        }
        return is_array($config) ? $config : [];
    }

    public static function requirePdo(): \PDO
    {
        $config = self::database();
        $required = ['hostname', 'hostport', 'database', 'username'];
        foreach ($required as $key) {
            if (($config[$key] ?? '') === '') {
                throw new RuntimeException('Publish runtime database configuration is incomplete.');
            }
        }
        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['hostname'],
                $config['hostport'],
                $config['database']
            ),
            $config['username'],
            $config['password'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }
}
