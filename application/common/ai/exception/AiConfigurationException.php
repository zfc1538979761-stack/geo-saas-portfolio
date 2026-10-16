<?php

declare(strict_types=1);

namespace app\common\ai\exception;

final class AiConfigurationException extends AiProviderException
{
    public function __construct(string $safeMessage)
    {
        parent::__construct($safeMessage, 'configuration_error', false);
    }
}
