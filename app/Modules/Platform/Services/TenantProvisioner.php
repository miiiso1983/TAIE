<?php

namespace App\Modules\Platform\Services;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TenantProvisioner
{
    public function create(array $data, ?int $actorId = null): Tenant
    {
        return DB::transaction(function () use ($data, $actorId) {
            $tenant = Tenant::query()->create(['slug' => $data['slug'], 'name' => $data['name'], 'status' => 'active']);
            $owner = User::query()->create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => Hash::make($data['password']), 'status' => 'active']);
            $tenant->users()->attach($owner, ['status' => 'active', 'joined_at' => now()]);
            $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Tenant Owner', 'guard_name' => 'api']);
            $role->permissions()->sync(Permission::query()->where('guard_name', 'api')->where('name', 'not like', 'platform.%')->pluck('id'));
            DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $owner->id]);
            $context = app(TenantContext::class);
            $previous = $context->hasTenant() ? $context->getTenant() : null;
            $context->setTenant($tenant);
            try {
                app(AuditTrailService::class)->record('tenant.provisioned', ['module' => 'platform', 'entity_type' => 'tenant', 'entity_id' => $tenant->uuid, 'user_id' => $actorId ?? $owner->id]);
            } finally {
                $context->clear();
                if ($previous) {
                    $context->setTenant($previous);
                }
            }

            return $tenant;
        });
    }
}
