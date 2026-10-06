<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class Decision extends TenantModel
{
    protected $table = 'advisor_decisions';

    protected function casts(): array
    {
        return ['details_encrypted' => 'encrypted:array', 'due_date' => 'date', 'version' => 'integer'];
    }
}
