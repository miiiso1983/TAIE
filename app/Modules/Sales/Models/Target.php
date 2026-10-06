<?php

namespace App\Modules\Sales\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;

class Target extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'sales_targets';

    protected function casts(): array
    {
        return ['target_value' => 'decimal:2', 'actual_value' => 'decimal:2', 'period_starts_on' => 'date', 'period_ends_on' => 'date'];
    }
}
