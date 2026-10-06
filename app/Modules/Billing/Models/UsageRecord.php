<?php

namespace App\Modules\Billing\Models;

use App\Modules\Shared\Models\TenantModel;

class UsageRecord extends TenantModel
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'occurred_at' => 'immutable_datetime', 'metadata' => 'array'];
    }
}
