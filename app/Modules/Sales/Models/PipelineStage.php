<?php

namespace App\Modules\Sales\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'sales_pipeline_stages';

    protected function casts(): array
    {
        return ['default_probability' => 'decimal:2', 'is_won' => 'boolean', 'is_lost' => 'boolean'];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'stage_id');
    }
}
