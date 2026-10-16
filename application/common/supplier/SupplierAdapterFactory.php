<?php

declare(strict_types=1);

namespace app\common\supplier;

use app\common\supplier\contract\SupplierAdapter;
use app\common\supplier\exception\SupplierConfigurationException;
use app\common\supplier\exception\SupplierUnsupportedCapabilityException;

final class SupplierAdapterFactory
{
    public static function create(array $config): SupplierAdapter
    {
        $driver = strtolower(trim((string) ($config['driver'] ?? '')));
        $environment = strtolower(trim((string) ($config['environment'] ?? 'production')));
        if ($driver === 'mock') {
            if (!in_array($environment, ['development', 'testing'], true)) {
                throw new SupplierConfigurationException('Mock Supplier Adapter is forbidden outside development/testing.');
            }
            return new MockSupplierAdapter($environment, (array) ($config['mock'] ?? []));
        }
        // Real supplier integrations are intentionally absent from Portfolio Edition.
        throw new SupplierConfigurationException('Configured supplier driver is unsupported or missing.');
    }

    private function __construct()
    {
    }
}
