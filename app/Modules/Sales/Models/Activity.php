<?php

namespace App\Modules\Sales\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'sales_activities';

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function subjectable(): MorphTo
    {
        return $this->morphTo();
    }
}
