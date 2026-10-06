<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceCycle extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_performance_cycles';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'cycle_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class, 'cycle_id');
    }
}
