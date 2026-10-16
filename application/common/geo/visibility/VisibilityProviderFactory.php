<?php

declare(strict_types=1);

namespace app\common\geo\visibility;

final class VisibilityProviderFactory
{
    public static function make(array $config, ?VisibilityProvider $responsesWebSearchProvider = null): VisibilityProvider
    {
        $driver = trim((string) ($config['driver'] ?? 'responses_web_search'));
        if ($driver === 'mock') {
            $environment = strtolower(trim((string) ($config['environment'] ?? 'production')));
            if (!in_array($environment, ['development', 'testing'], true)) {
                throw new VisibilityProviderException('Mock Visibility Provider is forbidden outside development/testing.', 'configuration', $driver);
            }
            $mock = (array) ($config['mock'] ?? []);
            return new MockVisibilityProvider(
                (string) ($mock['raw_answer'] ?? 'Mock visibility answer.'),
                (array) ($mock['citations'] ?? []),
                (array) ($mock['evidence'] ?? [])
            );
        }
        if ($driver !== 'responses_web_search') {
            throw new VisibilityProviderException('Visibility Provider configuration is invalid.', 'configuration', $driver);
        }
        $enabled = filter_var($config['web_search_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return new UnavailableVisibilityProvider($driver, (string) ($config['external_code'] ?? 'ToolNotOpen'));
        }
        if ($responsesWebSearchProvider === null || $responsesWebSearchProvider->providerCode() !== $driver) {
            throw new VisibilityProviderException(
                'Responses Web Search adapter is not configured.',
                'configuration',
                $driver,
                'AdapterNotConfigured',
                false
            );
        }
        return $responsesWebSearchProvider;
    }

    private function __construct()
    {
    }
}
