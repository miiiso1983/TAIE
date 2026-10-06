<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_payroll_items';

    protected $hidden = ['base_pay_encrypted', 'allowances_encrypted', 'deductions_encrypted', 'net_pay_encrypted'];

    protected function casts(): array
    {
        return [
            'base_pay_encrypted' => 'encrypted',
            'allowances_encrypted' => 'encrypted',
            'deductions_encrypted' => 'encrypted',
            'net_pay_encrypted' => 'encrypted',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
