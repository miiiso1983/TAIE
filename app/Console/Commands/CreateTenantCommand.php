<?php

namespace App\Console\Commands;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateTenantCommand extends Command
{
    protected $signature = 'teami:tenant:create {slug} {name} {owner_email} {owner_name}';

    protected $description = 'Create a tenant and its first owner account.';

    public function handle(TenantContext $context, AuditTrailService $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('owner_email')));
        if (User::query()->where('email', $email)->exists()) {
            $this->error('That email address already belongs to an account.');

            return self::FAILURE;
        }
        $password = $this->secret('Owner password (minimum 12 characters, with mixed case, a number, and a symbol)');
        $confirmation = $this->secret('Confirm owner password');
        if ($password !== $confirmation || Validator::make(['password' => $password], ['password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]])->fails()) {
            $this->error('The password confirmation or strength requirements failed.');

            return self::FAILURE;
        }

        $tenant = DB::transaction(function () use ($context, $audit, $email, $password): Tenant {
            $tenant = Tenant::query()->create(['slug' => $this->argument('slug'), 'name' => $this->argument('name'), 'status' => 'active']);
            $owner = User::query()->create(['name' => $this->argument('owner_name'), 'email' => $email, 'password' => Hash::make($password), 'status' => 'active']);
            $tenant->users()->attach($owner, ['status' => 'active', 'joined_at' => now()]);
            $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Tenant Owner', 'guard_name' => 'api']);
            $permissions = Permission::query()->where('guard_name', 'api')->where('name', 'not like', 'platform.%')->get();
            $role->permissions()->sync($permissions->modelKeys());
            DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $owner->id]);
            $context->setTenant($tenant);
            try {
                $audit->record('tenant.provisioned', ['module' => 'tenancy', 'entity_type' => 'tenant', 'entity_id' => $tenant->uuid, 'user_id' => $owner->id]);
            } finally {
                $context->clear();
            }

            return $tenant;
        });

        $this->info('Tenant and owner account were created.');
        $this->line('Tenant UUID: '.$tenant->uuid);
        $this->line('Owner email: '.$email);

        return self::SUCCESS;
    }
}
