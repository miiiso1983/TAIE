<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit records are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit records are append-only.'));
    }

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
