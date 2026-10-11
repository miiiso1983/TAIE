<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Repositories\TenantSettingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_without_accept_header_returns_json(): void
    {
        $this->get('/api/tenant/context')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_tenant_scoped_repository_cannot_return_another_tenants_record(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::query()->create(['name' => 'B', 'slug' => 'b']);
        app(TenantContext::class)->setTenant($tenantA);
        $settingA = TenantSetting::query()->create(['tenant_id' => $tenantA->id, 'key' => 'secret', 'value' => 'A']);
        app(TenantContext::class)->setTenant($tenantB);
        $settingB = TenantSetting::query()->create(['tenant_id' => $tenantB->id, 'key' => 'secret', 'value' => 'B']);
        app(TenantContext::class)->setTenant($tenantA);

        $this->assertSame($settingA->id, app(TenantSettingRepository::class)->find($settingA->id)?->id);
        $this->assertNull(app(TenantSettingRepository::class)->find($settingB->id));
    }

    public function test_tenant_owned_models_fail_closed_without_context(): void
    {
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        app(TenantContext::class)->setTenant($tenant);
        TenantSetting::query()->create(['key' => 'private', 'value' => 'value']);
        app(TenantContext::class)->clear();

        $this->assertNull(TenantSetting::query()->first());
        $this->expectException(\LogicException::class);
        TenantSetting::query()->create(['tenant_id' => $tenant->id, 'key' => 'unsafe', 'value' => 'value']);
    }

    public function test_authenticated_member_can_use_tenant_context_but_other_tenant_is_denied(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::query()->create(['name' => 'B', 'slug' => 'b']);
        $user = User::factory()->create();
        $tenantA->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant', $tenantA->uuid)->getJson('/api/tenant/context')->assertOk()->assertJsonPath('data.tenant.uuid', $tenantA->uuid)->assertHeader('X-Request-ID');
        $this->withHeader('X-Tenant', $tenantB->uuid)->getJson('/api/tenant/context')->assertForbidden()->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
        $this->assertDatabaseHas('security_events', ['event_type' => 'cross_tenant_attempt', 'tenant_id' => $tenantB->id]);
    }

    public function test_tenant_context_fails_closed_when_missing(): void
    {
        $this->expectException(\LogicException::class);
        app(TenantContext::class)->getTenantId();
    }

    public function test_idempotency_replays_a_successful_response_without_repeating_the_action(): void
    {
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active']);
        $counter = 0;
        Route::post('/api/test/idempotent', function () use (&$counter) {
            $counter++;

            return response()->json(['count' => $counter]);
        })
            ->middleware(['auth:sanctum', 'tenant.resolve', 'tenant.membership', 'tenant.active', 'idempotency']);
        $this->actingAs($user)->withHeaders(['X-Tenant' => $tenant->uuid, 'Idempotency-Key' => 'once'])
            ->postJson('/api/test/idempotent', ['amount' => 7])->assertOk()->assertJsonPath('count', 1);
        $this->postJson('/api/test/idempotent', ['amount' => 7])->assertOk()->assertJsonPath('count', 1);
        $this->assertSame(1, $counter);
    }

    public function test_login_returns_an_mfa_challenge_without_issuing_a_token(): void
    {
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $user = User::factory()->create(['email' => 'mfa@example.com', 'password' => Hash::make('password')]);
        $tenant->users()->attach($user, ['status' => 'active']);
        $user->mfaCredentials()->create(['type' => 'totp', 'secret_encrypted' => 'JBSWY3DPEHPK3PXP', 'verified_at' => now()]);

        $this->postJson('/api/auth/login', ['email' => 'mfa@example.com', 'password' => 'password', 'tenant' => $tenant->slug])
            ->assertStatus(202)->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_issues_a_sanctum_token_after_membership_check(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('a', 64)]);
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $user = User::factory()->create(['email' => 'login@example.com', 'password' => Hash::make('password')]);
        $tenant->users()->attach($user, ['status' => 'active']);
        $login = $this->postJson('/api/auth/login', ['email' => 'LOGIN@example.com', 'password' => 'password', 'tenant' => $tenant->slug])->assertOk();
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);
        $this->withToken($token)->withHeader('X-Tenant', $tenant->uuid)->getJson('/api/tenant/context')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'tenant_id' => $tenant->id]);
    }

    public function test_totp_enrollment_verifies_the_code_and_gates_login(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('b', 64)]);
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $user = User::factory()->create(['email' => 'totp@example.com', 'password' => Hash::make('password')]);
        $tenant->users()->attach($user, ['status' => 'active']);

        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $setup = $this->postJson('/api/auth/mfa/setup')->assertOk();
        $secret = $setup->json('data.secret');
        $code = $this->totpCode($secret);
        $confirmation = $this->postJson('/api/auth/mfa/confirm', ['code' => $code])->assertOk();
        $this->assertCount(8, $confirmation->json('data.recovery_codes'));

        $this->postJson('/api/auth/login', ['email' => 'totp@example.com', 'password' => 'password', 'tenant' => $tenant->slug])
            ->assertStatus(202)->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->postJson('/api/auth/login', ['email' => 'totp@example.com', 'password' => 'password', 'tenant' => $tenant->slug, 'mfa_code' => $this->totpCode($secret)])
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_password_reset_request_does_not_disclose_account_existence(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.com']);
        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])->assertOk()->json('data.message');
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->json('data.message');
        $this->assertSame($known, $unknown);
    }

    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', 0, intdiv(time(), 30)), $key, true);
        $position = ord($hash[19]) & 0x0F;
        $number = ((ord($hash[$position]) & 0x7F) << 24) | ((ord($hash[$position + 1]) & 0xFF) << 16) | ((ord($hash[$position + 2]) & 0xFF) << 8) | (ord($hash[$position + 3]) & 0xFF);

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
