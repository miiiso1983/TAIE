<?php

namespace Tests\Feature;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Platform\Models\CompanyTrialRegistration;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('a', 48), 'teami.billing_enforce' => true, 'teami_signup.enabled' => true]);
        Cache::flush();
        Carbon::setTestNow('2026-10-30 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'name' => 'Fixture company', 'legal_name' => 'Fixture legal company', 'business_activity' => 'technology',
            'country_code' => 'IQ', 'city' => 'Baghdad', 'address' => 'Fixture street 123', 'company_size' => '6-20',
            'website' => 'https://example.test', 'owner_name' => 'Fixture Owner', 'job_title' => 'Company Director',
            'owner_email' => 'owner@example.test', 'phone' => '+9647701234567', 'password' => 'Fixture-Password42!',
            'password_confirmation' => 'Fixture-Password42!', 'consent' => true, 'contact_code' => '',
        ], $changes);
    }

    private function register(array $changes = [], ?string $key = null)
    {
        return $this->postJson('/api/public/trials', $this->payload($changes), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function signIntoTrial(): Tenant
    {
        $r = $this->register()->assertCreated();
        $tenant = Tenant::query()->where('uuid', $r->json('data.tenant.uuid'))->firstOrFail();
        Sanctum::actingAs(User::query()->where('email', 'owner@example.test')->firstOrFail());
        $this->withHeader('X-Tenant', $tenant->uuid);

        return $tenant;
    }

    public function test_public_catalog_only_exposes_published_paid_plans_and_honest_ai_availability(): void
    {
        config(['teami_ai.api_key' => null, 'teami_ai.endpoint' => null]);
        BillingPlan::query()->create(['uuid' => Str::uuid(), 'code' => 'published', 'name' => 'Public plan', 'currency' => 'USD', 'unit_amount' => 2500, 'interval' => 'month', 'is_active' => true, 'is_public' => true, 'limits' => ['users' => 10]]);
        BillingPlan::query()->create(['uuid' => Str::uuid(), 'code' => 'internal', 'name' => 'Hidden plan', 'currency' => 'USD', 'unit_amount' => 900, 'interval' => 'month', 'is_active' => true, 'is_public' => false]);
        $r = $this->getJson('/api/public/catalog')->assertOk()->assertJsonCount(1, 'data.plans')->assertJsonPath('data.plans.0.name', 'Public plan')->assertJsonPath('data.ai_available', false)->assertJsonPath('data.trial.ai_requests_total', 25);
        $this->assertStringNotContainsString('Hidden plan', $r->getContent());
        $this->assertStringNotContainsString('api_key', $r->getContent());
        $this->assertStringNotContainsString('self_service_trial_v1', $r->getContent());
    }

    public function test_registration_creates_isolated_owner_and_exact_four_day_trial_without_invoice(): void
    {
        $r = $this->register(['owner_email' => '  OWNER@example.test ', 'phone' => '00964 770 123 4567', 'platform_owner' => true])->assertCreated();
        $tenant = Tenant::query()->where('uuid', $r->json('data.tenant.uuid'))->firstOrFail();
        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($user->hasTenantPermission($tenant->id, 'ai.ask'));
        $this->assertFalse($user->hasTenantPermission($tenant->id, 'platform.billing.manage'));
        $this->assertDatabaseCount('platform_owners', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertSame('technology', $tenant->business_activity);
        app(TenantContext::class)->setTenant($tenant);
        $sub = app(BillingService::class)->entitledSubscription();
        $this->assertSame(96.0, $sub->starts_at->diffInHours($sub->ends_at));
        $this->assertSame(25, $sub->plan->limits['ai_requests_per_trial']);
        $this->assertTrue(app(AuditTrailService::class)->verifyChain($tenant->id));
        $this->assertStringNotContainsString('Fixture-Password42!', $r->getContent());
    }

    public function test_signup_contact_profile_is_encrypted_and_only_site_owner_can_read_it(): void
    {
        $tenant = $this->signIntoTrial();
        $this->assertStringNotContainsString('Fixture street', DB::table('company_trial_registrations')->value('profile_encrypted'));
        $this->assertStringNotContainsString('+964770', DB::table('company_trial_registrations')->value('profile_encrypted'));
        $this->assertStringNotContainsString('Fixture-Password42!', json_encode(DB::table('company_trial_registrations')->first()));
        $this->getJson('/api/platform/tenants/'.$tenant->uuid.'/registration')->assertForbidden();
        $operator = User::factory()->create();
        DB::table('platform_owners')->insert(['user_id' => $operator->id, 'granted_at' => now()]);
        Sanctum::actingAs($operator);
        $r = $this->getJson('/api/platform/tenants/'.$tenant->uuid.'/registration')->assertOk()->assertJsonPath('data.profile.phone', '+9647701234567')->assertJsonPath('data.profile.email_verified', false);
        $this->assertStringNotContainsString('password', $r->getContent());
        $this->assertStringNotContainsString('request_key', $r->getContent());
    }

    public function test_registration_retry_does_not_extend_trial_or_create_duplicate_users(): void
    {
        $key = (string) Str::uuid();
        $r = $this->register(key: $key)->assertCreated();
        Carbon::setTestNow('2026-10-30 13:00:00 UTC');
        $this->register(key: $key)->assertOk()->assertJsonPath('data.trial.ends_at', $r->json('data.trial.ends_at'));
        $this->register(['name' => 'Other company'], $key)->assertConflict();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('tenant_subscriptions', 1);
        $this->assertDatabaseCount('company_trial_registrations', 1);
    }

    public function test_reusing_contact_number_or_email_cannot_reset_the_trial(): void
    {
        $this->register()->assertCreated();
        $this->register(['owner_email' => 'second@example.test'])->assertUnprocessable();
        $this->register(['phone' => '+9647701234568'])->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_required_details_consent_strong_password_and_request_key_are_enforced(): void
    {
        $this->register(['consent' => false, 'address' => '', 'phone' => '123', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertUnprocessable()->assertJsonValidationErrors(['consent', 'address', 'phone', 'password'], 'error.details');
        $this->postJson('/api/public/trials', $this->payload())->assertUnprocessable();
        $this->register(['contact_code' => 'bot'])->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_successful_requests_are_capped_at_25_across_a_month_boundary_and_replays_do_not_charge(): void
    {
        $tenant = $this->signIntoTrial();
        Route::post('/api/ai/trial-probe', fn () => response()->json(['answer' => 'fixture']))->middleware(['auth:sanctum', 'tenant.resolve', 'tenant.membership', 'billing.access', 'idempotency', 'billing.ai-quota']);
        Route::post('/api/ai/trial-failure', fn () => response()->json([], 503))->middleware(['auth:sanctum', 'tenant.resolve', 'tenant.membership', 'billing.access', 'billing.ai-quota']);
        $this->postJson('/api/ai/trial-failure', [])->assertStatus(503);
        $this->assertDatabaseCount('usage_records', 0);
        for ($i = 0; $i < 24; $i++) {
            $this->postJson('/api/ai/trial-probe', [], ['Idempotency-Key' => 'trial-'.$i])->assertOk();
        }
        Carbon::setTestNow('2026-11-01 00:00:00 UTC');
        $this->postJson('/api/ai/trial-probe', [], ['Idempotency-Key' => 'trial-last'])->assertOk();
        $this->postJson('/api/ai/trial-probe', [], ['Idempotency-Key' => 'trial-last'])->assertOk();
        $this->postJson('/api/ai/trial-probe', [], ['Idempotency-Key' => 'trial-over'])->assertStatus(429);
        $this->assertDatabaseCount('usage_records', 25);
        $this->getJson('/api/billing/trial')->assertOk()->assertJsonPath('data.remaining', 0)->assertJsonPath('data.state', 'limit_reached');
        $this->assertSame(1, TenantSubscription::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
    }

    public function test_expiry_blocks_changes_and_ai_while_login_read_and_billing_remain_available(): void
    {
        $tenant = $this->signIntoTrial();
        Carbon::setTestNow('2026-11-03 12:00:00 UTC');
        $this->postJson('/api/hr/departments', ['code' => 'OPS', 'name' => 'Operations'])->assertForbidden();
        $this->postJson('/api/ai/ask', ['question' => 'Question'])->assertForbidden();
        $this->postJson('/api/files', [])->assertForbidden();
        $this->postJson('/api/integrations/connections', [])->assertForbidden();
        $this->getJson('/api/tenant/context')->assertOk();
        $this->getJson('/api/hr/departments')->assertOk();
        $this->getJson('/api/billing/trial')->assertOk()->assertJsonPath('data.state', 'expired');
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($tenant->users()->exists());
    }

    public function test_public_registration_can_be_paused_and_is_rate_limited(): void
    {
        config(['teami_signup.enabled' => false]);
        $this->register()->assertStatus(503);
        config(['teami_signup.enabled' => true]);
        $this->register(['consent' => false])->assertUnprocessable();
        $this->register(['consent' => false])->assertUnprocessable();
        $this->register(['consent' => false])->assertStatus(429);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_plan_interest_is_validated_without_granting_paid_access_or_creating_an_invoice(): void
    {
        $trial = BillingPlan::query()->where('code', 'self_service_trial_v1')->firstOrFail();
        $this->register(['preferred_plan_uuid' => $trial->uuid])->assertUnprocessable();
        $plan = BillingPlan::query()->create(['uuid' => Str::uuid(), 'code' => 'public-interest', 'name' => 'Public interest', 'currency' => 'USD', 'unit_amount' => 1000, 'interval' => 'month', 'is_public' => true]);
        $this->register(['preferred_plan_uuid' => $plan->uuid])->assertCreated();
        $this->assertSame((string) $plan->uuid, CompanyTrialRegistration::query()->firstOrFail()->profile_encrypted['preferred_plan_uuid']);
        $this->assertDatabaseHas('tenant_subscriptions', ['billing_plan_id' => $trial->id, 'status' => 'trialing']);
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_only_platform_owner_can_publish_a_plan_and_internal_trial_never_becomes_public(): void
    {
        $operator = User::factory()->create();
        Sanctum::actingAs($operator);
        $plan = BillingPlan::query()->create(['uuid' => Str::uuid(), 'code' => 'business', 'name' => 'Business', 'currency' => 'USD', 'unit_amount' => 1000, 'interval' => 'month', 'is_public' => false]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $path = '/api/platform/plans/'.$plan->uuid.'/publication';
        $this->patchJson($path, ['is_public' => true], $headers)->assertForbidden();
        DB::table('platform_owners')->insert(['user_id' => $operator->id, 'granted_at' => now()]);
        $this->patchJson($path, ['is_public' => true], $headers)->assertCreated();
        $this->patchJson($path, ['is_public' => true], $headers)->assertOk();
        $this->getJson('/api/public/catalog')->assertOk()->assertJsonCount(1, 'data.plans');
        $trial = BillingPlan::query()->where('code', 'self_service_trial_v1')->firstOrFail();
        $this->patchJson('/api/platform/plans/'.$trial->uuid.'/publication', ['is_public' => true], ['Idempotency-Key' => Str::uuid()])->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'platform.plan.publication_changed')->count());
    }
}
