<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends TenantModel
{
    protected $table = 'hr_attendance_records';

    protected function casts(): array
    {
        return ['work_date' => 'date', 'check_in_at' => 'immutable_datetime', 'check_out_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
