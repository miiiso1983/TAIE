<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_amount' => 'integer', 'limits' => 'array', 'is_active' => 'boolean'];
    }
}
