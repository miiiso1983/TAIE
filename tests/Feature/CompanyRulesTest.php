<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyRulesTest extends TestCase
{
    use RefreshDatabase;

    private const RIGHTS = ['advisor.rule.manage', 'advisor.rule.approve', 'advisor.sales.read', 'sales.customer.read', 'hr.employee.read', 'hr.leave.read', 'hr.compensation.read', 'data.sensitive.read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('d', 48), 'teami.billing_enforce' => false]);
    }

    private function actor(array $rights = self::RIGHTS, ?Tenant $t = null): array
    {
        $t ??= Tenant::query()->create(['name' => 'Decision Company', 'slug' => Str::random(12)]);
        $u = User::factory()->create(['status' => 'active']);
        $t->users()->attach($u, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $t->id, 'name' => 'Manager '.Str::random(5), 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $rights)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_id' => $role->id]);
        $this->sign($t, $u);

        return [$t, $u];
    }

    private function sign(Tenant $t, User $u): void
    {
        $this->actingAs($u)->withHeaders(['X-Tenant' => $t->uuid]);
        app(TenantContext::class)->setTenant($t);
    }

    private function callRule(string $path, array $data)
    {
        return $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/company-rules'.$path, $data);
    }

    private function policy(string $kind, array $rules): string
    {
        return $this->callRule('', ['name' => 'Configured company policy', 'kind' => $kind, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'rules' => $rules])->assertCreated()->json('data.uuid');
    }

    private function simulation(string $id, array $input)
    {
        return $this->callRule('/'.$id.'/simulate', ['on_date' => '2026-10-07', 'evidence_reference' => 'Fixture independently calculated', 'input' => $input]);
    }

    public function test_credit_boundary_and_independent_activation_require_pass_and_fail_cases(): void
    {
        [$t,$u] = $this->actor();
        $id = $this->policy('credit', ['currency' => 'USD', 'limit_minor' => 1000, 'maximum_overdue_days' => 10]);
        $status = ['status' => 'active', 'review_note' => 'Independent manager verified boundary cases', 'acknowledge' => true];
        $this->callRule('/'.$id.'/status', $status)->assertForbidden();
        $this->simulation($id, ['currency' => 'USD', 'amount_minor' => 400, 'outstanding_minor' => 600, 'overdue_days' => 10])->assertOk()->assertJsonPath('data.snapshot.passed', true);
        $this->actor(self::RIGHTS, $t);
        $this->callRule('/'.$id.'/status', $status)->assertStatus(422);
        $this->simulation($id, ['currency' => 'USD', 'amount_minor' => 401, 'outstanding_minor' => 600, 'overdue_days' => 10])->assertOk()->assertJsonPath('data.snapshot.passed', false);
        $this->callRule('/'.$id.'/status', $status)->assertOk()->assertJsonPath('data.status', 'active');
        $this->callRule('/'.$id.'/status', [...$status, 'status' => 'disabled'])->assertOk()->assertJsonCount(2, 'data.status_history_encrypted');
        $this->assertStringNotContainsString('Independent manager', DB::table('advisor_company_rules')->value('status_history_encrypted'));
    }

    public function test_leave_precision_and_deduction_aggregate_cap_use_configured_rules_only(): void
    {
        $this->actor();
        $id = $this->policy('leave', ['maximum_days' => '3.50', 'require_balance' => true]);
        $this->simulation($id, ['requested_days' => '3.50', 'balance_days' => '3.50'])->assertOk()->assertJsonPath('data.snapshot.passed', true);
        $this->simulation($id, ['requested_days' => '3.51', 'balance_days' => '3.50'])->assertOk()->assertJsonPath('data.snapshot.passed', false);
        $this->simulation($id, ['requested_days' => '0', 'balance_days' => '3.50'])->assertOk()->assertJsonPath('data.snapshot.passed', false);
        $id = $this->policy('deduction', ['currency' => 'IQD', 'maximum_percent' => '10.50']);
        $this->simulation($id, ['currency' => 'IQD', 'base_minor' => 1000, 'amount_minor' => 55, 'other_deductions_minor' => 50])->assertOk()->assertJsonPath('data.snapshot.passed', true)->assertJsonPath('data.snapshot.checks.1.limit', '105');
        $this->simulation($id, ['currency' => 'IQD', 'base_minor' => 1000, 'amount_minor' => 56, 'other_deductions_minor' => 50])->assertOk()->assertJsonPath('data.snapshot.passed', false);
        $this->assertDatabaseCount('hr_payroll_runs', 0);
        $this->assertDatabaseCount('hr_leave_requests', 0);
    }

    public function test_policy_simulations_reject_extra_fields_currency_errors_and_foreign_tenants(): void
    {
        $this->actor();
        $id = $this->policy('deduction', ['currency' => 'USD', 'maximum_percent' => '10']);
        $this->simulation($id, ['currency' => 'IQD', 'base_minor' => 1000, 'amount_minor' => 100, 'other_deductions_minor' => 0])->assertOk()->assertJsonPath('data.snapshot.passed', false);
        $this->simulation($id, ['currency' => 'USD', 'base_minor' => 1000, 'amount_minor' => 100, 'other_deductions_minor' => 0, 'ignore_rules' => true])->assertStatus(422);
        $this->actor();
        $this->getJson('/api/advisor/company-rules/'.$id.'/simulations')->assertNotFound();
        $this->getJson('/api/advisor/company-rules')->assertJsonPath('data.total', 0);
        $this->actor(['data.sensitive.read', 'advisor.rule.manage']);
        $this->callRule('', ['name' => 'Blocked', 'kind' => 'deduction', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'rules' => ['currency' => 'USD', 'maximum_percent' => '10']])->assertForbidden();
    }
}
