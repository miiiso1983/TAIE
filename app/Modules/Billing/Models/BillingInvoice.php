<?php

namespace App\Modules\Billing\Models;

use App\Modules\Shared\Models\TenantModel;

class BillingInvoice extends TenantModel
{
    protected $table = 'billing_invoices';

    protected function casts(): array
    {
        return ['period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'amount_minor' => 'integer'];
    }
}
