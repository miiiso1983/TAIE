<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class CompanyRule extends TenantModel
{
    protected $table = 'advisor_company_rules';

    protected function casts(): array
    {
        return ['rules_encrypted' => 'encrypted:array', 'status_history_encrypted' => 'encrypted:array', 'approval_note_encrypted' => 'encrypted', 'version' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date'];
    }
}
