<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRelationsCase extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_employee_relations_cases';

    protected $hidden = ['description_encrypted'];

    protected function casts(): array
    {
        return ['description_encrypted' => 'encrypted', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
