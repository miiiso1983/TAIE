<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_payroll_runs';

    protected $hidden = ['base_total_encrypted', 'allowance_total_encrypted', 'deduction_total_encrypted', 'net_total_encrypted'];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'base_total_encrypted' => 'encrypted',
            'allowance_total_encrypted' => 'encrypted',
            'deduction_total_encrypted' => 'encrypted',
            'net_total_encrypted' => 'encrypted',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class, 'payroll_run_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }
}
