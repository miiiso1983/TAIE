<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class BonusRequest extends TenantModel
{
    protected $table = 'advisor_bonus_requests';

    protected $hidden = ['reason_encrypted', 'snapshot_encrypted', 'decision_reason_encrypted', 'fingerprint'];

    protected function casts(): array
    {
        return ['reason_encrypted' => 'encrypted', 'snapshot_encrypted' => 'encrypted:array', 'decision_reason_encrypted' => 'encrypted', 'amount_minor' => 'integer', 'overridden' => 'boolean', 'period_from' => 'date', 'period_to' => 'date', 'as_of' => 'date', 'evaluated_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
