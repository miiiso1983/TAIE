<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_departments';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_department_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }
}
