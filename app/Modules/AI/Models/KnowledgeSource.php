<?php

namespace App\Modules\AI\Models;

use App\Modules\Shared\Models\HasPublicUuid;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeSource extends TenantModel
{
    use HasPublicUuid;

    protected $table = 'knowledge_sources';

    protected $hidden = ['external_review_note_encrypted'];

    protected function casts(): array
    {
        return ['external_metadata' => 'array', 'external_review_note_encrypted' => 'encrypted'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class, 'source_id');
    }
}
