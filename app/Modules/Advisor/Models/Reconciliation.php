<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class Reconciliation extends TenantModel
{
    protected $table = 'advisor_reconciliations';

    protected $hidden = ['snapshot_encrypted', 'evidence_reference_encrypted', 'review_note_encrypted', 'fingerprint'];

    protected function casts(): array
    {
        return ['snapshot_encrypted' => 'encrypted:array', 'evidence_reference_encrypted' => 'encrypted', 'review_note_encrypted' => 'encrypted', 'period_from' => 'date', 'period_to' => 'date', 'as_of' => 'date'];
    }
}
