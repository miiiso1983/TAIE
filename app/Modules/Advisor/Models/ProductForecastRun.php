<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class ProductForecastRun extends TenantModel
{
    protected $table = 'advisor_product_forecast_runs';

    protected $hidden = ['snapshot_encrypted', 'input_hash'];

    protected function casts(): array
    {
        return ['snapshot_encrypted' => 'encrypted:array'];
    }
}
