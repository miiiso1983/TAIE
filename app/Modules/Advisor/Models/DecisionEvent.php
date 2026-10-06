<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class DecisionEvent extends TenantModel
{
    public $timestamps = false;

    protected $table = 'advisor_decision_events';

    protected function casts(): array
    {
        return ['evidence_encrypted' => 'encrypted:array', 'version' => 'integer'];
    }
}
