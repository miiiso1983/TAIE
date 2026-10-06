<?php

namespace App\Modules\AI\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentProfile extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'ai_agent_profiles';

    protected function casts(): array
    {
        return ['allowed_source_uuids' => 'array', 'active' => 'boolean'];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AIConversation::class, 'agent_profile_id');
    }
}
