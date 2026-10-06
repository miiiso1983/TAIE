<?php

namespace App\Http\Controllers\Api;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Billing\Services\BillingService;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class MembershipController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $members = $context->requireTenant()->users()->orderBy('name')->paginate(25);

        return response()->json(['data' => $members->getCollection()->map(fn ($user) => [
            'uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email, 'status' => $user->pivot->status,
            'permissions' => Permission::query()->pluck('name')->filter(fn ($p) => $user->hasTenantPermission($context->getTenantId(), $p))->values(),
            'employee_uuid' => Employee::query()->where('user_id', $user->id)->value('uuid'),
        ]), 'meta' => ['current_page' => $members->currentPage(), 'last_page' => $members->lastPage()]]);
    }

    public function permissions(Request $request, TenantContext $context)
    {
        return response()->json(['data' => Permission::query()->where('name', 'not like', 'platform.%')->get(['name', 'module'])->filter(fn ($p) => $request->user()->hasTenantPermission($context->getTenantId(), $p->name))->values()]);
    }

    private function validatePermissions(Request $request, array $permissions, TenantContext $context): array
    {
        foreach ($permissions as $permission) {
            abort_if(str_starts_with($permission, 'platform.') || ! $request->user()->hasTenantPermission($context->getTenantId(), $permission), 403, 'You may delegate only your own tenant permissions.');
        }

        return Permission::query()->whereIn('name', $permissions)->pluck('id')->all();
    }

    public function store(Request $request, TenantContext $context, AuditTrailService $audit, Outbox $outbox, BillingService $billing)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')], 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()], 'permissions' => ['present', 'array', 'max:100'], 'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')]]);
        $ids = $this->validatePermissions($request, $data['permissions'], $context);
        $user = DB::transaction(function () use ($context, $data, $ids, $audit, $outbox, $billing) {
            $tenant = $context->requireTenant()->newQuery()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $billing->assertSeatAvailable();
            $user = User::query()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'status' => 'active']);
            $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
            $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Member '.$user->uuid, 'guard_name' => 'api']);
            $role->permissions()->sync($ids);
            DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id]);
            $audit->record('identity.member.created', ['module' => 'identity', 'entity_type' => 'user', 'entity_id' => $user->uuid]);
            $outbox->append('identity.member.created', ['user_uuid' => $user->uuid]);

            return $user;
        });

        return response()->json(['data' => ['uuid' => $user->uuid, 'name' => $user->name]], 201);
    }

    public function update(Request $request, string $uuid, TenantContext $context, AuditTrailService $audit, BillingService $billing)
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['active', 'suspended'])], 'permissions' => ['sometimes', 'array', 'max:100'], 'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')]]);
        abort_if($data === [], 422, 'Provide status or permissions.');
        $ids = isset($data['permissions']) ? $this->validatePermissions($request, $data['permissions'], $context) : null;
        DB::transaction(function () use ($request, $uuid, $context, $data, $ids, $audit, $billing) {
            $tenant = $context->requireTenant()->newQuery()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $user = $tenant->users()->where('users.uuid', $uuid)->firstOrFail();
            abort_if($user->id === $request->user()->id, 409, 'You cannot change your own membership.');
            $roles = Role::query()->whereIn('id', DB::table('role_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->select('role_id'))->get();
            abort_if($roles->contains(fn ($r) => $r->is_system || $r->name === 'Tenant Owner' || $r->tenant_id === null), 403, 'Protected accounts are managed from the trusted terminal.');
            // Prevent removing permissions delegated by a more privileged administrator.
            foreach ($roles as $r) {
                $this->validatePermissions($request, $r->permissions()->pluck('name')->all(), $context);
            }
            if (($data['status'] ?? null) === 'active' && $user->pivot->status !== 'active') {
                $billing->assertSeatAvailable();
            }
            if (isset($data['status'])) {
                $tenant->users()->updateExistingPivot($user->id, ['status' => $data['status']]);
            }
            if ($ids !== null) {
                $role = Role::query()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Member '.$user->uuid, 'guard_name' => 'api']);
                $role->permissions()->sync($ids);
                DB::table('role_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
                DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id]);
            }
            $audit->record('identity.member.updated', ['module' => 'identity', 'entity_type' => 'user', 'entity_id' => $user->uuid, 'new_values' => ['status' => $data['status'] ?? $user->pivot->status]]);
        });

        return response()->json(['data' => ['uuid' => $uuid]]);
    }

    public function linkEmployee(Request $request, string $uuid, TenantContext $context, AuditTrailService $audit)
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid']]);
        DB::transaction(function () use ($data, $uuid, $context, $audit) {
            $context->requireTenant()->newQuery()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $user = $context->requireTenant()->users()->where('users.uuid', $uuid)->wherePivot('status', 'active')->firstOrFail();
            $employee = Employee::query()->where('uuid', $data['employee_uuid'])->lockForUpdate()->firstOrFail();
            abort_if(($employee->user_id && $employee->user_id !== $user->id) || Employee::query()->where('user_id', $user->id)->whereKeyNot($employee->id)->exists(), 409, 'An account can be linked to only one employee.');
            $employee->update(['user_id' => $user->id]);
            $audit->record('identity.employee.linked', ['module' => 'identity', 'entity_type' => 'employee', 'entity_id' => $employee->uuid, 'new_values' => ['user_uuid' => $user->uuid]]);
        });

        return response()->json(['data' => ['employee_uuid' => $data['employee_uuid']]]);
    }
}
