<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class BonusPolicy extends TenantModel
{
    protected $table = 'advisor_bonus_policies';

    protected function casts(): array
    {
        return ['rules' => 'array', 'version' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date'];
    }
}
