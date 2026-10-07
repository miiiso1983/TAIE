<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class IdentityLink extends TenantModel
{
    protected $table = 'advisor_identity_links';

    protected $hidden = ['external_hash'];

    protected function casts(): array
    {
        return ['review_note_encrypted' => 'encrypted', 'external_id_encrypted' => 'encrypted', 'revocation_note_encrypted' => 'encrypted', 'revoked_at' => 'datetime', 'version' => 'integer'];
    }
}
