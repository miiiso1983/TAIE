<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }
}
