<?php

namespace App\Modules\AI\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDocument extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'knowledge_documents';

    protected function casts(): array
    {
        return ['body_encrypted' => 'encrypted'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }
}
