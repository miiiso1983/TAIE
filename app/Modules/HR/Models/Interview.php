<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interview extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'hr_interviews';

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'score' => 'decimal:2'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interviewer_employee_id');
    }
}
