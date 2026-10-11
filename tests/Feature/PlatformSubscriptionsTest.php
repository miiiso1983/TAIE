<?php

namespace Tests\Feature;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private BillingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config(['teami.audit_hmac_key' => str_repeat('a', 48)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 12:00:00', 'UTC'));
        $user = User::factory()->create();
        DB::table('platform_owners')->insert(['user_id' => $user->id, 'granted_at' => now()]);
        Sanctum::actingAs($user);
        $this->tenant = Tenant::query()->create(['name' => 'Company', 'slug' => 'company', 'status' => 'active']);
        $this->plan = BillingPlan::query()->create(['uuid' => Str::uuid(), 'code' => 'basic', 'name' => 'Basic', 'currency' => 'USD', 'unit_amount' => 1000, 'interval' => 'month', 'is_active' => true, 'limits' => ['users' => 2, 'ai_requests_per_month' => 100]]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['tenant_uuid' => $this->tenant->uuid, 'plan_uuid' => $this->plan->uuid, 'starts_on' => '2026-10-11', 'ends_on' => '2026-11-10', 'reason' => 'Signed company agreement'], $changes);
    }

    private function grant(array $changes = [], ?string $key = null)
    {
        return $this->postJson('/api/platform/subscriptions', $this->payload($changes), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_subscription_activates_and_expires_at_baghdad_calendar_boundaries(): void
    {
        $this->grant()->assertCreated()->assertJsonPath('data.state', 'active')->assertJsonPath('data.ends_on', '2026-11-10');
        $this->assertFalse(app(TenantContext::class)->hasTenant());
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertTrue(app(AuditTrailService::class)->verifyChain($this->tenant->id));
        app(TenantContext::class)->setTenant($this->tenant);
        $service = app(BillingService::class);
        $this->assertNotNull($service->entitledSubscription());
        CarbonImmutable::setTestNow('2026-11-10 20:59:59 UTC');
        $this->assertNotNull($service->entitledSubscription());
        CarbonImmutable::setTestNow('2026-11-10 21:00:00 UTC');
        $this->assertNull($service->entitledSubscription());
    }

    public function test_future_renewal_has_no_early_entitlement_and_overlap_is_rejected(): void
    {
        $this->grant(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30'])->assertCreated()->assertJsonPath('data.state', 'scheduled');
        $this->grant(['starts_on' => '2026-11-30', 'ends_on' => '2026-12-30'])->assertConflict();
        $this->grant(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31'])->assertCreated();
        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertNull(app(BillingService::class)->entitledSubscription());
        CarbonImmutable::setTestNow('2026-10-31 21:00:00 UTC');
        $this->assertNotNull(app(BillingService::class)->entitledSubscription());
    }

    public function test_duplicate_retry_and_changed_payload_are_safe(): void
    {
        $key = (string) Str::uuid();
        $r = $this->grant(key: $key)->assertCreated();
        $this->grant(key: $key)->assertOk()->assertJsonPath('data.uuid', $r->json('data.uuid'));
        $this->grant(['reason' => 'Changed agreement'], $key)->assertConflict();
        $this->assertDatabaseCount('tenant_subscriptions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_non_owner_cannot_read_or_activate_subscriptions_or_create_plans(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/platform/subscriptions')->assertForbidden();
        $this->getJson('/api/platform/plans')->assertForbidden();
        $this->grant()->assertForbidden();
        $this->postJson('/api/platform/plans', [])->assertForbidden();
        $this->assertDatabaseCount('tenant_subscriptions', 0);
    }

    public function test_invalid_dates_inactive_plans_and_seat_limits_do_not_create_subscriptions(): void
    {
        $this->grant(['ends_on' => '2026-10-10'])->assertUnprocessable();
        $this->grant(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertUnprocessable();
        $this->plan->update(['is_active' => false]);
        $this->grant()->assertConflict();
        $this->plan->update(['is_active' => true, 'limits' => ['users' => 1]]);
        $this->tenant->users()->attach(User::factory()->count(2)->create(), ['status' => 'active', 'joined_at' => now()]);
        $this->grant()->assertConflict();
        $this->assertDatabaseCount('tenant_subscriptions', 0);
    }

    public function test_calendar_lists_cross_month_periods_and_expired_status_and_searches_companies(): void
    {
        $this->grant(['starts_on' => '2026-10-11', 'ends_on' => '2026-12-10'])->assertCreated();
        $this->getJson('/api/platform/subscriptions?month=2026-11')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.company', 'Company');
        $this->getJson('/api/platform/subscriptions?month=2027-01')->assertOk()->assertJsonPath('total', 0);
        CarbonImmutable::setTestNow('2026-12-11 00:00:00 UTC');
        $this->getJson('/api/platform/subscriptions?month=2026-12')->assertOk()->assertJsonPath('data.0.state', 'expired');
        $this->getJson('/api/platform/tenants?search=Comp')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/platform/tenants?search=Other')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_plan_creation_is_validated_audited_and_idempotent(): void
    {
        $data = ['code' => 'pro', 'name' => 'Professional', 'currency' => 'IQD', 'unit_amount' => 1000000, 'interval' => 'year', 'users' => 10, 'ai_requests_per_month' => 200];
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $r = $this->postJson('/api/platform/plans', $data, $key)->assertCreated()->assertJsonPath('data.limits.users', 10);
        $this->postJson('/api/platform/plans', $data, $key)->assertOk()->assertJsonPath('data.uuid', $r->json('data.uuid'));
        $this->postJson('/api/platform/plans', array_replace($data, ['users' => 0]), ['Idempotency-Key' => Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('billing_plans', 2);
        $this->assertTrue(app(AuditTrailService::class)->verifyChain(null));
    }
}
