<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class SourceAuthority extends TenantModel
{
    protected $table = 'advisor_source_authorities';

    protected $hidden = ['external_hash'];

    protected function casts(): array
    {
        return ['review_note_encrypted' => 'encrypted', 'version' => 'integer'];
    }
}
