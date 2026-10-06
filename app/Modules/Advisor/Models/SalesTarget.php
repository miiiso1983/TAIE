<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class SalesTarget extends TenantModel
{
    protected $table = 'advisor_sales_targets';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'period_from' => 'date', 'period_to' => 'date'];
    }
}
