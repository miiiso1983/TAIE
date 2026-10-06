<?php

namespace App\Modules\Billing\Models;

use App\Modules\Shared\Models\TenantModel;

class BillingPayment extends TenantModel
{
    protected $table = 'billing_payments';

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'amount_minor' => 'integer'];
    }
}
