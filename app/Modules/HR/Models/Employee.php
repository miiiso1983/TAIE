<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends TenantModel
{
    use HasPublicUuid, SoftDeletes;

    protected $table = 'hr_employees';

    protected $hidden = ['national_id_encrypted', 'emergency_contact_encrypted', 'personal_email', 'date_of_birth', 'phone'];

    protected function casts(): array
    {
        return ['national_id_encrypted' => 'encrypted', 'emergency_contact_encrypted' => 'encrypted:array', 'hired_at' => 'date', 'terminated_at' => 'date', 'date_of_birth' => 'date'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_employee_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_employee_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function compensationRecords(): HasMany
    {
        return $this->hasMany(CompensationRecord::class);
    }
}
