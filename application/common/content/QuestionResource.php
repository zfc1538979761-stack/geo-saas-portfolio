<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\tenant\TenantOwnedResource;

final class QuestionResource implements TenantOwnedResource
{
    public function tenantResourceName(): string
    {
        return 'questions';
    }
}
