<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyTrialRegistration extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload_hash', 'phone_fingerprint', 'request_key'];

    protected function casts(): array
    {
        return ['profile_encrypted' => 'encrypted:array', 'result' => 'array', 'consented_at' => 'immutable_datetime'];
    }
}
