<?php

namespace App\Modules\AI\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIConversation extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'ai_conversations';

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AIMessage::class, 'conversation_id');
    }
}
