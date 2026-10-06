<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['response_encrypted' => 'encrypted:array', 'response_body' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
