<?php

namespace App\Modules\Billing\Models;

use App\Modules\Shared\Models\TenantModel;

class TenantSubscription extends TenantModel
{
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'trial_ends_at' => 'immutable_datetime', 'cancel_at' => 'immutable_datetime'];
    }

    public function plan()
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }
}
