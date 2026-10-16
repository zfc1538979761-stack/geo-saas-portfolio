<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\internal\InternalAccessPolicy;
use app\common\tenant\InternalAdminIdentity;

function t16AccessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

t16AccessAssert(
    InternalAccessPolicy::permission('AiConfig', 'replace') === 'internal/ai_config/replace',
    'Controller action maps to internal permission path.'
);
t16AccessAssert(
    InternalAccessPolicy::isHighRisk('internal/wallet/credit'),
    'Wallet credit is classified as high risk.'
);
t16AccessAssert(
    !InternalAccessPolicy::isHighRisk('internal/audit/index'),
    'Read-only audit access is not high risk.'
);

$identity = new InternalAdminIdentity(7, ['internal/company/index']);
t16AccessAssert($identity->id() === 7, 'Internal admin identity preserves actor ID.');
t16AccessAssert($identity->hasPermission('internal/company/index'), 'Low-permission admin has granted read permission.');
t16AccessAssert(!$identity->hasPermission('internal/company/status'), 'Low-permission admin lacks high-risk permission.');

echo "PASS: T16 access policy tests passed\n";
