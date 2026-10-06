<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Goal extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_goals';

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'progress_percent' => 'decimal:2', 'due_on' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }
}
