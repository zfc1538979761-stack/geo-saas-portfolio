<?php

declare(strict_types=1);

namespace app\common\content\storage;

final class StorageAdapterFactory
{
    private static $sharedMock;

    public static function create(string $environment, array $config): StorageAdapter
    {
        $driver = strtolower(trim((string) ($config['driver'] ?? '')));
        if ($driver === 'mock') {
            if (!in_array($environment, ['development', 'testing'], true)) {
                throw new StorageException('MediaMarket OSS Mock is forbidden in production.');
            }
            if (!empty($config['portfolio_root'])) { return new \app\common\portfolio\PortfolioStorageAdapter($environment,(string)$config['portfolio_root']); }
            if (!self::$sharedMock instanceof MockMediaMarketStorageAdapter) {
                $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                    . 'geo-saas-mediamarket-mock-process-' . getmypid();
                self::$sharedMock = new MockMediaMarketStorageAdapter($environment, $root, true);
            }
            return self::$sharedMock;
        }
        if ($driver === 'media_market') {
            return new UnavailableMediaMarketStorageAdapter();
        }
        throw new StorageException('MediaMarket OSS storage driver is not configured.');
    }

    private function __construct()
    {
    }
}
