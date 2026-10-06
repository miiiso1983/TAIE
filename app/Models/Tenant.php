<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'name', 'legal_name', 'slug', 'status', 'country_code', 'timezone', 'default_language', 'currency_code', 'settings_json', 'business_activity'];

    protected static function booted(): void
    {
        static::creating(function (self $tenant) {
            $tenant->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['settings_json' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users', 'tenant_id', 'user_id')->withPivot(['status', 'joined_at', 'last_active_at'])->withTimestamps();
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }
}
