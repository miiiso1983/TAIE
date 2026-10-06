<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_leave_types';

    protected function casts(): array
    {
        return ['paid' => 'boolean', 'requires_approval' => 'boolean', 'annual_allowance_days' => 'decimal:2'];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
