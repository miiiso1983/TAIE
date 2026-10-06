<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class InvoiceLine extends TenantModel
{
    protected $table = 'advisor_invoice_lines';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'net_amount_minor' => 'integer'];
    }
}
