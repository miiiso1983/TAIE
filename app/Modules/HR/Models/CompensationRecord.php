<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensationRecord extends TenantModel
{
    protected $table = 'hr_compensation_records';

    protected $hidden = ['annual_base_encrypted'];

    protected function casts(): array
    {
        return ['annual_base_encrypted' => 'encrypted', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
