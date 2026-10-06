<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidate extends TenantModel
{
    use HasPublicUuid, SoftDeletes;

    protected $table = 'hr_candidates';

    protected $hidden = ['email', 'phone'];

    protected function casts(): array
    {
        return ['skills_json' => 'array'];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
