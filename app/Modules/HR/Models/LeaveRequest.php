<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_leave_requests';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'requested_days' => 'decimal:2', 'decided_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'decided_by');
    }
}
