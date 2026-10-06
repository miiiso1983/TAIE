<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends TenantModel
{
    protected $table = 'hr_leave_balances';

    protected function casts(): array
    {
        return ['allocated_days' => 'decimal:2', 'used_days' => 'decimal:2', 'pending_days' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
