<?php

namespace App\Modules\AI\Models;

use App\Modules\Shared\Models\TenantModel;

class CompanyPolicyDraft extends TenantModel
{
    protected $table = 'company_policy_drafts';

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['body_encrypted' => 'encrypted', 'approved_at' => 'datetime'];
    }
}
