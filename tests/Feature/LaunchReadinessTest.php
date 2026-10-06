<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\AI\Jobs\IndexKnowledgeDocument;
use App\Modules\AI\Models\KnowledgeDocument;
use App\Modules\AI\Models\KnowledgeSource;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\HR\Models\Employee;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['teami.audit_hmac_key' => str_repeat('l', 48), 'teami.billing_enforce' => false]);
        $this->seed();
    }

    private function actor(array $permissions, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->create(['name' => 'Fixture Company', 'slug' => 't-'.Str::random(8), 'timezone' => 'Asia/Baghdad']);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Fixture '.Str::uuid(), 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id]);

        return [$tenant, $user];
    }

    private function sign(Tenant $tenant, User $user): void
    {
        $this->actingAs($user)->withHeaders(['X-Tenant' => $tenant->uuid, 'Idempotency-Key' => (string) Str::uuid()]);
        app(TenantContext::class)->setTenant($tenant);
    }

    private function plan(int $users = 5, int $requests = 10): BillingPlan
    {
        return BillingPlan::query()->create(['uuid' => (string) Str::uuid(), 'code' => 'p-'.Str::random(6), 'name' => 'Pilot', 'currency' => 'USD', 'unit_amount' => 30000, 'interval' => 'month', 'limits' => ['users' => $users, 'ai_requests_per_month' => $requests]]);
    }

    private function active(Tenant $tenant, BillingPlan $plan): void
    {
        app(TenantContext::class)->setTenant($tenant);
        TenantSubscription::query()->create(['uuid' => (string) Str::uuid(), 'billing_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    }

    private function invoice(Tenant $tenant, User $operator): array
    {
        $this->sign($tenant, $operator);

        return $this->postJson('/api/billing/invoices', ['plan_uuid' => $this->plan()->uuid, 'starts_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString()])->assertCreated()->json('data');
    }

    public function test_manual_transfer_requires_separate_operator_and_activates_only_once(): void
    {
        [$tenant,$operator] = $this->actor(['platform.billing.manage', 'billing.invoice.read', 'billing.subscription.read', 'billing.payment.report']);
        [, $reviewer] = $this->actor(['platform.billing.manage'], $tenant);
        $invoice = $this->invoice($tenant, $operator);
        $payment = $this->postJson('/api/billing/invoices/'.$invoice['uuid'].'/payments', ['reference' => ' bank-100 ', 'amount_minor' => 30000])->assertCreated()->json('data');
        $this->getJson('/api/billing/subscription')->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/billing/payments/'.$payment['uuid'].'/decision', ['decision' => 'approve', 'note' => 'Bank checked'])->assertForbidden();
        $this->sign($tenant, $reviewer);
        $key = 'verify-transfer';
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/billing/payments/'.$payment['uuid'].'/decision', ['decision' => 'approve', 'note' => 'Bank checked'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/billing/payments/'.$payment['uuid'].'/decision', ['decision' => 'approve', 'note' => 'Bank checked'])->assertOk();
        $this->withHeader('Idempotency-Key', 'another-decision')->postJson('/api/billing/payments/'.$payment['uuid'].'/decision', ['decision' => 'approve', 'note' => 'Bank checked'])->assertStatus(409);
        $this->assertDatabaseHas('billing_invoices', ['uuid' => $invoice['uuid'], 'status' => 'paid']);
        $this->assertDatabaseHas('billing_payments', ['uuid' => $payment['uuid'], 'reference' => 'BANK-100']);
        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $invoice['subscription_id'], 'status' => 'active']);
        $this->assertDatabaseCount('billing_payments', 1);
    }

    public function test_invoice_payment_amount_overlap_and_tenant_isolation_are_enforced(): void
    {
        [$tenant,$operator] = $this->actor(['platform.billing.manage', 'billing.invoice.read', 'billing.subscription.read', 'billing.payment.report']);
        $invoice = $this->invoice($tenant, $operator);
        $this->postJson('/api/billing/invoices/'.$invoice['uuid'].'/payments', ['reference' => 'ref', 'amount_minor' => 1])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'overlap')->postJson('/api/billing/invoices', ['plan_uuid' => $this->plan()->uuid, 'starts_at' => today()->toDateString(), 'due_at' => today()->toDateString()])->assertStatus(409);
        [$other,$otherOperator] = $this->actor(['platform.billing.manage', 'billing.invoice.read', 'billing.payment.report']);
        $this->sign($other, $otherOperator);
        $this->getJson('/api/billing/invoices')->assertJsonCount(0, 'data.data');
        $this->postJson('/api/billing/invoices/'.$invoice['uuid'].'/payments', ['reference' => 'ref', 'amount_minor' => 30000])->assertNotFound();
        $this->postJson('/api/billing/invoices/'.$invoice['uuid'].'/void')->assertNotFound();
    }

    public function test_tenant_owner_cannot_self_activate_a_paid_plan(): void
    {
        [$tenant,$user] = $this->actor(['identity.membership.manage', 'billing.subscription.read', 'billing.invoice.read']);
        $this->sign($tenant, $user);
        $this->postJson('/api/billing/plans', [])->assertForbidden();
        $this->postJson('/api/billing/invoices', [])->assertForbidden();
        $this->postJson('/api/billing/payments/'.Str::uuid().'/decision', [])->assertForbidden();
    }

    public function test_members_cannot_delegate_platform_or_unheld_permissions_and_can_be_suspended(): void
    {
        [$tenant,$manager] = $this->actor(['identity.membership.manage', 'hr.employee.read']);
        $this->sign($tenant, $manager);
        $body = ['name' => 'New Member', 'email' => 'new.member@example.test', 'password' => 'Fixture!Password123', 'permissions' => ['hr.employee.read']];
        $member = $this->postJson('/api/identity/members', $body)->assertCreated()->json('data.uuid');
        $this->withHeader('Idempotency-Key', 'escalate')->postJson('/api/identity/members', [...$body, 'email' => 'another@example.test', 'permissions' => ['platform.billing.manage']])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'escalate-hr')->patchJson('/api/identity/members/'.$member, ['permissions' => ['hr.payroll.approve']])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'self')->patchJson('/api/identity/members/'.$manager->uuid, ['status' => 'suspended'])->assertStatus(409);
        $this->withHeader('Idempotency-Key', 'suspend')->patchJson('/api/identity/members/'.$member, ['status' => 'suspended'])->assertOk();
        $user = User::query()->where('uuid', $member)->firstOrFail();
        $this->sign($tenant, $user);
        $this->getJson('/api/tenant/context')->assertForbidden();
    }

    public function test_seat_limits_and_expired_subscription_preserve_read_access(): void
    {
        [$tenant,$manager] = $this->actor(['identity.membership.manage', 'hr.department.manage', 'hr.department.read']);
        $this->active($tenant, $this->plan(1));
        config(['teami.billing_enforce' => true]);
        $this->sign($tenant, $manager);
        $this->postJson('/api/identity/members', ['name' => 'Excess', 'email' => 'excess@example.test', 'password' => 'Fixture!Password123', 'permissions' => []])->assertStatus(409);
        app(TenantContext::class)->setTenant($tenant);
        TenantSubscription::query()->update(['ends_at' => now()->subSecond()]);
        $this->postJson('/api/hr/departments', ['code' => 'new', 'name' => 'New'])->assertForbidden();
        $this->getJson('/api/hr/departments')->assertOk();
        $this->getJson('/api/tenant/context')->assertOk();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_ai_quota_counts_success_and_replay_once_and_releases_failed_attempts(): void
    {
        [$tenant,$user] = $this->actor([]);
        $this->active($tenant, $this->plan(3, 1));
        config(['teami.billing_enforce' => true]);
        Route::post('/api/ai/quota-probe', fn () => response()->json(['answer' => 'fixture']))->middleware(['auth:sanctum', 'tenant.resolve', 'tenant.membership', 'idempotency', 'billing.ai-quota']);
        Route::post('/api/ai/failure-probe', fn () => response()->json(['error' => 'fixture'], 503))->middleware(['auth:sanctum', 'tenant.resolve', 'tenant.membership', 'idempotency', 'billing.ai-quota']);
        $this->sign($tenant, $user);
        $this->postJson('/api/ai/failure-probe', [])->assertStatus(503);
        $this->assertDatabaseCount('usage_records', 0);
        $this->withHeader('Idempotency-Key', 'ask-one')->postJson('/api/ai/quota-probe', [])->assertOk();
        $this->postJson('/api/ai/quota-probe', [])->assertOk();
        $this->assertDatabaseCount('usage_records', 1);
        $this->withHeader('Idempotency-Key', 'ask-two')->postJson('/api/ai/quota-probe', [])->assertStatus(429);
    }

    public function test_idempotency_is_bound_to_actual_record_path_and_encrypts_sensitive_responses(): void
    {
        [$tenant,$user] = $this->actor([]);
        $counter = 0;
        Route::post('/api/launch-records/{uuid}', function (string $uuid) use (&$counter) {
            $counter++;

            return response()->json(['uuid' => $uuid, 'payroll_amount' => 'PRIVATE-9750']);
        })->middleware(['auth:sanctum', 'tenant.resolve', 'tenant.membership', 'idempotency']);
        $this->sign($tenant, $user);
        $this->withHeader('Idempotency-Key', 'shared-key')->postJson('/api/launch-records/a', ['x' => 1])->assertJsonPath('uuid', 'a');
        $this->postJson('/api/launch-records/b', ['x' => 1])->assertJsonPath('uuid', 'b');
        $this->postJson('/api/launch-records/a', ['x' => 1])->assertJsonPath('uuid', 'a');
        $this->assertSame(2, $counter);
        foreach (DB::table('idempotency_keys')->get() as $row) {
            $this->assertNull($row->response_body);
            $this->assertStringNotContainsString('PRIVATE-9750', $row->response_encrypted);
        }
        $this->postJson('/api/launch-records/a', ['x' => 2])->assertStatus(409);
    }

    public function test_linking_employee_rejects_foreign_tenant_and_multiple_links(): void
    {
        [$tenant,$user] = $this->actor(['identity.membership.manage', 'hr.employee.update']);
        app(TenantContext::class)->setTenant($tenant);
        $first = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'E1', 'first_name' => 'One', 'last_name' => 'Person']);
        $second = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'E2', 'first_name' => 'Two', 'last_name' => 'Person']);
        [$other,$otherUser] = $this->actor([]);
        app(TenantContext::class)->setTenant($other);
        $foreign = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'E3', 'first_name' => 'Other', 'last_name' => 'Person']);
        $this->sign($tenant, $user);
        $this->patchJson('/api/identity/members/'.$user->uuid.'/employee', ['employee_uuid' => $first->uuid])->assertOk();
        $this->withHeader('Idempotency-Key', 'another-link')->patchJson('/api/identity/members/'.$user->uuid.'/employee', ['employee_uuid' => $second->uuid])->assertStatus(409);
        $this->withHeader('Idempotency-Key', 'foreign-link')->patchJson('/api/identity/members/'.$user->uuid.'/employee', ['employee_uuid' => $foreign->uuid])->assertNotFound();
    }

    public function test_compensation_change_closes_previous_period_atomically(): void
    {
        [$tenant,$user] = $this->actor(['hr.compensation.write']);
        app(TenantContext::class)->setTenant($tenant);
        $employee = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'E1', 'first_name' => 'One', 'last_name' => 'Person']);
        $this->sign($tenant, $user);
        $this->postJson('/api/hr/employees/'.$employee->uuid.'/compensation', ['annual_base' => 12000, 'currency_code' => 'USD', 'effective_from' => '2026-01-01'])->assertCreated();
        $this->withHeader('Idempotency-Key', 'increase')->postJson('/api/hr/employees/'.$employee->uuid.'/compensation', ['annual_base' => 24000, 'currency_code' => 'USD', 'effective_from' => '2026-10-01', 'close_previous' => true])->assertCreated();
        $this->assertDatabaseHas('hr_compensation_records', ['effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-09-30 00:00:00']);
        $this->assertDatabaseCount('hr_compensation_records', 2);
    }

    public function test_knowledge_documents_retry_is_tenant_scoped_and_state_guarded(): void
    {
        Queue::fake();
        [$tenant,$user] = $this->actor(['ai.knowledge.read', 'ai.knowledge.manage']);
        app(TenantContext::class)->setTenant($tenant);
        $source = KnowledgeSource::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Handbook', 'created_by' => $user->id]);
        $doc = KnowledgeDocument::query()->create(['uuid' => (string) Str::uuid(), 'source_id' => $source->id, 'title' => 'Policy', 'body_encrypted' => 'Fixture policy', 'content_hash' => hash('sha256', 'Fixture policy'), 'status' => 'failed', 'created_by' => $user->id]);
        $this->sign($tenant, $user);
        $this->getJson('/api/knowledge/sources/'.$source->uuid.'/documents')->assertJsonPath('data.data.0.title', 'Policy');
        $this->postJson('/api/knowledge/documents/'.$doc->uuid.'/retry', [])->assertStatus(202);
        $this->withHeader('Idempotency-Key', 'retry-twice')->postJson('/api/knowledge/documents/'.$doc->uuid.'/retry', [])->assertStatus(409);
        Queue::assertPushed(IndexKnowledgeDocument::class, 1);
        [$other,$otherUser] = $this->actor(['ai.knowledge.read', 'ai.knowledge.manage']);
        $this->sign($other, $otherUser);
        $this->getJson('/api/knowledge/sources/'.$source->uuid.'/documents')->assertNotFound();
    }

    public function test_owner_permission_upgrade_strips_platform_power(): void
    {
        [$tenant,$user] = $this->actor([]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Tenant Owner']);
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->artisan('teami:permissions:sync-owners')->assertSuccessful();
        $this->assertFalse($role->permissions()->where('name', 'like', 'platform.%')->exists());
        $this->assertTrue($role->permissions()->where('name', 'billing.invoice.read')->exists());
    }

    public function test_password_reset_link_and_security_headers_and_readiness_fail_closed(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $notification = new ResetPassword('fixture-token');
        $url = $notification->toMail($user)->actionUrl;
        $this->assertStringContainsString('/reset-password/fixture-token',$url);
        $this->get('/reset-password/fixture-token?email=fixture@example.test')->assertOk()->assertHeader('X-Frame-Options','DENY');
        $this->artisan('teami:launch:check')->expectsOutputToContain('FAIL Production environment')->assertFailed();
    }
}
