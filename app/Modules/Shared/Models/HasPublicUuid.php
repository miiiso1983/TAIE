<?php

namespace App\Modules\Shared\Models;

use Illuminate\Support\Str;

trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }
}
