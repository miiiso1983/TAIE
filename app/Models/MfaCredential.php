<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaCredential extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['secret_encrypted' => 'encrypted', 'recovery_codes_hashed' => 'array', 'verified_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
