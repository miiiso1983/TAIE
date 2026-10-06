<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class ImportBatch extends TenantModel
{
    protected $table = 'advisor_import_batches';

    protected $hidden = ['payload_encrypted', 'errors_encrypted'];

    protected function casts(): array
    {
        return ['payload_encrypted' => 'encrypted:array', 'errors_encrypted' => 'encrypted:array', 'expires_at' => 'datetime', 'committed_at' => 'datetime'];
    }
}
