<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class RuleSimulation extends TenantModel
{
    protected $table = 'advisor_rule_simulations';

    protected function casts(): array
    {
        return ['snapshot_encrypted' => 'encrypted:array', 'passed' => 'boolean'];
    }
}
