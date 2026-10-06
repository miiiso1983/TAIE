<?php

namespace App\Modules\AI\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIMessage extends TenantModel
{
    protected $table = 'ai_messages';

    protected function casts(): array
    {
        return ['content_encrypted' => 'encrypted', 'citations' => 'array', 'usage' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AIConversation::class, 'conversation_id');
    }
}
