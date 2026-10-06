<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class SalesReturn extends TenantModel
{
    protected $table = 'advisor_sales_returns';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'return_date' => 'date'];
    }
}
