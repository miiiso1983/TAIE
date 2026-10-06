<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_applications';

    protected function casts(): array
    {
        return ['screening_score' => 'decimal:2', 'applied_at' => 'immutable_datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(JobRequisition::class, 'requisition_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }
}
