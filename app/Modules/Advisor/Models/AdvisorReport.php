<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class AdvisorReport extends TenantModel
{
    protected $table = 'advisor_reports';

    protected $hidden = ['snapshot_encrypted', 'fingerprint'];

    protected function casts(): array
    {
        return ['snapshot_encrypted' => 'encrypted:array', 'period_from' => 'date', 'period_to' => 'date', 'as_of' => 'date', 'version' => 'integer'];
    }
}
