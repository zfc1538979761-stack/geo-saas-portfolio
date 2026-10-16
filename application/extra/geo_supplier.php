<?php
use think\Env;
return [
    'driver'=>getenv('GEO_SUPPLIER_DRIVER')?:Env::get('supplier.driver','mock'),
    'environment'=>getenv('GEO_APP_ENV')?:Env::get('app.env','development'),
    'mock'=>['scenario'=>getenv('GEO_SUPPLIER_MOCK_SCENARIO')?:Env::get('supplier.mock_scenario','default')],
];
