<?php

namespace App\Modules\Sales\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends TenantModel
{
    use HasPublicUuid, SoftDeletes;

    protected $table = 'sales_leads';

    protected $hidden = ['email', 'phone'];

    protected function casts(): array
    {
        return ['converted_at' => 'immutable_datetime'];
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }
}
