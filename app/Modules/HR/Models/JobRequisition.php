<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobRequisition extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_job_requisitions';

    protected function casts(): array
    {
        return ['opened_on' => 'date', 'closed_on' => 'date'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'requisition_id');
    }
}
