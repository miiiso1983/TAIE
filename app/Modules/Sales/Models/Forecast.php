<?php

namespace App\Modules\Sales\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;

class Forecast extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'sales_forecasts';

    protected function casts(): array
    {
        return ['period_starts_on' => 'date', 'period_ends_on' => 'date', 'pipeline_amount' => 'decimal:2', 'weighted_amount' => 'decimal:2', 'commit_amount' => 'decimal:2', 'generated_at' => 'immutable_datetime'];
    }
}
