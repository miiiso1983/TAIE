<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class ForecastSchedule extends TenantModel
{
    protected $table = 'advisor_forecast_schedules';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'review_pending' => 'boolean', 'version' => 'integer', 'history_weeks' => 'integer', 'local_hour' => 'integer', 'next_run_at' => 'datetime', 'review_from' => 'date', 'review_to' => 'date'];
    }
}
