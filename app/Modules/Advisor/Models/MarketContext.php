<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class MarketContext extends TenantModel
{
    protected $table = 'advisor_market_context';

    protected $hidden = ['payload_encrypted', 'revocation_note_encrypted'];

    protected function casts(): array
    {
        return ['payload_encrypted' => 'encrypted:array', 'revocation_note_encrypted' => 'encrypted', 'period_from' => 'date', 'period_to' => 'date', 'revoked_at' => 'datetime'];
    }
}
