<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class CoverageConfirmation extends TenantModel
{
    protected $table = 'advisor_coverage_confirmations';

    protected $hidden = ['review_encrypted', 'fingerprint'];

    protected function casts(): array
    {
        return ['review_encrypted' => 'encrypted:array'];
    }
}
