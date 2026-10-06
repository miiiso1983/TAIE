<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class SalesReceipt extends TenantModel
{
    protected $table = 'advisor_sales_receipts';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'receipt_date' => 'date'];
    }
}
