<?php

namespace App\Modules\AI\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends TenantModel
{
    protected $table = 'knowledge_chunks';

    protected function casts(): array
    {
        return ['content_encrypted' => 'encrypted', 'embedding' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
