<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class ReportSchedule extends TenantModel
{
    protected $table = 'advisor_report_schedules';

    protected $hidden = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'local_hour' => 'integer', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }
}
