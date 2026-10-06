<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_onboarding_tasks';

    protected function casts(): array
    {
        return ['due_on' => 'date', 'completed_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
