<?php

namespace Tests\Feature;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('a', 48)]);
        $user = User::factory()->create();
        DB::table('platform_owners')->insert(['user_id' => $user->id, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(): array
    {
        return ['slug' => 'new-company', 'name' => 'New Company', 'owner_name' => 'Owner', 'owner_email' => 'owner@example.test', 'password' => 'Fixture-Password42!', 'password_confirmation' => 'Fixture-Password42!'];
    }

    public function test_tenant_user_cannot_access_or_self_assign_platform_ownership(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/platform/context')->assertOk()->assertJsonPath('data.platform_owner', false);
        $this->getJson('/api/platform/tenants')->assertForbidden();
        $this->postJson('/api/platform/tenants', $this->payload(), ['Idempotency-Key' => Str::uuid()])->assertForbidden();
        $user->fill(['platform_owner' => true])->save();
        $this->getJson('/api/platform/tenants')->assertForbidden();
        $this->assertDatabaseCount('platform_owners', 0);
    }

    public function test_global_owner_can_provision_an_isolated_company_without_joining_it(): void
    {
        $actor = $this->owner();
        $r = $this->postJson('/api/platform/tenants', $this->payload(), ['Idempotency-Key' => Str::uuid()])->assertCreated();
        $tenant = Tenant::query()->where('uuid', $r->json('data.uuid'))->firstOrFail();
        $newOwner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($this->payload()['password'], $newOwner->password));
        $this->assertFalse($actor->tenants()->whereKey($tenant->id)->exists());
        $this->assertTrue($newOwner->hasTenantPermission($tenant->id, 'hr.employee.read'));
        $this->assertFalse($newOwner->hasTenantPermission($tenant->id, 'platform.tenant.manage'));
        $this->assertDatabaseMissing('platform_owners', ['user_id' => $newOwner->id]);
        $this->assertFalse(app(TenantContext::class)->hasTenant());
        $this->assertTrue(app(AuditTrailService::class)->verifyChain($tenant->id));
        $this->assertStringNotContainsString('Fixture-Password42!', $r->getContent());
        $this->assertStringNotContainsString('Fixture-Password42!', DB::table('audit_logs')->value('canonical_payload'));
        $this->getJson('/api/platform/tenants')->assertOk()->assertJsonPath('data.0.users_count', 1);
    }

    public function test_retries_do_not_duplicate_accounts_and_changed_payload_is_rejected(): void
    {
        $this->owner();
        $key = (string) Str::uuid();
        $r = $this->postJson('/api/platform/tenants', $this->payload(), ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/platform/tenants', $this->payload(), ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.uuid', $r->json('data.uuid'));
        $this->postJson('/api/platform/tenants', array_replace($this->payload(), ['name' => 'Different']), ['Idempotency-Key' => $key])->assertConflict();
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_invalid_password_and_missing_request_key_have_no_side_effects(): void
    {
        $this->owner();
        $this->postJson('/api/platform/tenants', array_replace($this->payload(), ['password' => 'short', 'password_confirmation' => 'short']), ['Idempotency-Key' => Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/platform/tenants', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_inactive_or_revoked_operator_cannot_access_tenants_with_existing_token(): void
    {
        $user = $this->owner();
        $user->update(['status' => 'inactive']);
        $this->getJson('/api/platform/tenants')->assertForbidden();
        $user->update(['status' => 'active']);
        DB::table('platform_owners')->where('user_id', $user->id)->delete();
        $this->getJson('/api/platform/tenants')->assertForbidden();
    }
}
