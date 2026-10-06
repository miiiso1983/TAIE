<?php

namespace App\Modules\Sales\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends TenantModel
{
    use HasPublicUuid, SoftDeletes;

    protected $table = 'sales_contacts';

    protected $hidden = ['email', 'phone'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
