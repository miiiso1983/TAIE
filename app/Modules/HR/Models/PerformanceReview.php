<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_performance_reviews';

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_employee_id');
    }

    public function decisionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by_user_id');
    }
}
