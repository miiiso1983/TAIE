<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class ForecastEvaluation extends TenantModel
{
    protected $table = 'advisor_forecast_evaluations';

    protected $hidden = ['snapshot_encrypted', 'input_hash'];

    protected function casts(): array
    {
        return ['snapshot_encrypted' => 'encrypted:array'];
    }
}
