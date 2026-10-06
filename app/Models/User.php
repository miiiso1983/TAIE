<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->uuid ??= (string) Str::uuid();
            $user->email = mb_strtolower(trim($user->email));
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'phone',
        'status',
        'last_login_at',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $attributes = ['status' => 'active'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users', 'user_id', 'tenant_id')->withPivot(['status', 'joined_at', 'last_active_at'])->withTimestamps();
    }

    public function mfaCredentials(): HasMany
    {
        return $this->hasMany(MfaCredential::class);
    }

    public function hasTenantPermission(int $tenantId, string $permission): bool
    {
        if (! $this->tenants()->whereKey($tenantId)->wherePivot('status', 'active')->exists()) {
            return false;
        }

        return Role::query()->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->whereIn('id', DB::table('role_user')->where('tenant_id', $tenantId)->where('user_id', $this->getKey())->select('role_id'))
            ->whereHas('permissions', fn ($query) => $query->where('permissions.name', $permission))->exists();
    }
}
