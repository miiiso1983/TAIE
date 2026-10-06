<?php

namespace App\Modules\Sales\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'sales_pipelines';

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('sort_order');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
