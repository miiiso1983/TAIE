<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class BonusApproval extends TenantModel
{
    public $timestamps = false;

    protected $table = 'advisor_bonus_approvals';

    protected $hidden = ['fingerprint'];

    protected function casts(): array
    {
        return ['reason_encrypted' => 'encrypted', 'stage' => 'integer', 'overridden' => 'boolean'];
    }
}
