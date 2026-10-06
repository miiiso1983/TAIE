<?php

namespace App\Console\Commands;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GrantBillingOperator extends Command
{
    protected $signature = 'teami:billing:operator {tenant} {email}';

    protected $description = 'Grant platform billing access to an existing trusted member in one tenant.';

    public function handle(TenantContext $context, AuditTrailService $audit): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        $user = $tenant?->users()->where('email', mb_strtolower($this->argument('email')))->wherePivot('status', 'active')->first();
        if (! $tenant || ! $user) {
            $this->error('Active tenant member not found.');

            return self::FAILURE;
        }
        $context->setTenant($tenant);
        try {
            DB::transaction(function () use ($tenant, $user, $audit) {
                $role = Role::query()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Platform Billing Operator', 'guard_name' => 'api'], ['is_system' => true]);
                $role->permissions()->sync(Permission::query()->whereIn('name', ['platform.billing.manage', 'billing.subscription.read', 'billing.invoice.read', 'billing.payment.report', 'billing.usage.read'])->pluck('id'));
                DB::table('role_user')->insertOrIgnore(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $user->id]);
                $audit->record('platform.billing_operator.granted', ['module' => 'platform', 'entity_type' => 'user', 'entity_id' => $user->uuid]);
            });
        } finally {
            $context->clear();
        }
        $this->info('Operator access granted for the selected tenant.');

        return self::SUCCESS;
    }
}
