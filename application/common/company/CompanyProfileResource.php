<?php

declare(strict_types=1);

namespace app\common\company;

use app\common\tenant\TenantOwnedResource;

final class CompanyProfileResource implements TenantOwnedResource
{
    public function tenantResourceName(): string
    {
        return 'company_profiles';
    }
}
