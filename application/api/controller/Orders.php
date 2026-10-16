<?php

declare(strict_types=1);

namespace app\api\controller;

/**
 * Compatibility alias for the documented /api/orders routes.
 *
 * The implementation remains in Order, so list/create/notification paths that
 * use the plural form share the exact same enterprise authorization and logic.
 */
final class Orders extends Order
{
    public function index()
    {
        if (strtoupper($this->request->method()) === 'POST') {
            return $this->create();
        }
        return parent::index();
    }
}
