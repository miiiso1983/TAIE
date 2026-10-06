<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class SalesInvoice extends TenantModel
{
    protected $table = 'advisor_sales_invoices';

    protected function casts(): array
    {
        return ['net_amount_minor' => 'integer', 'invoice_date' => 'date', 'due_date' => 'date'];
    }
}
