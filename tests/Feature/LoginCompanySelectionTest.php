<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginCompanySelectionTest extends TestCase
{
    use RefreshDatabase;

    private static int $fixtureAddress = 20;

    private function account(): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.++self::$fixtureAddress]);
        config(['teami.audit_hmac_key' => str_repeat('a', 64)]);
        $tenant = Tenant::create(['name' => 'Company', 'slug' => 'company']);
        $user = User::factory()->create(['email' => 'login@example.com', 'password' => 'fixture-password']);
        $tenant->users()->attach($user, ['status' => 'active']);

        return [$tenant, $user];
    }

    public function test_single_active_company_is_resolved_after_password_validation(): void
    {
        [$tenant] = $this->account();
        $this->postJson('/api/auth/login', ['email' => ' LOGIN@example.com ', 'password' => 'fixture-password'])
            ->assertOk()->assertJsonPath('data.tenant.uuid', $tenant->uuid);
    }

    public function test_wrong_password_and_explicit_wrong_company_never_issue_tokens(): void
    {
        $this->account();
        $this->postJson('/api/auth/login', ['email' => 'login@example.com', 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'login@example.com', 'password' => 'fixture-password', 'tenant' => 'foreign-company'])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_multiple_companies_require_an_explicit_selection(): void
    {
        [$tenant, $user] = $this->account();
        Tenant::create(['name' => 'Other', 'slug' => 'other'])->users()->attach($user, ['status' => 'active']);
        $this->postJson('/api/auth/login', ['email' => 'login@example.com', 'password' => 'fixture-password'])
            ->assertStatus(422)->assertJsonPath('error.code', 'TENANT_REQUIRED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/auth/login', ['email' => 'login@example.com', 'password' => 'fixture-password', 'tenant' => ' company '])
            ->assertOk()->assertJsonPath('data.tenant.uuid', $tenant->uuid);
    }

    public function test_inactive_membership_cannot_be_auto_selected(): void
    {
        [$tenant, $user] = $this->account();
        $tenant->users()->updateExistingPivot($user->id, ['status' => 'inactive']);
        $this->postJson('/api/auth/login', ['email' => 'login@example.com', 'password' => 'fixture-password'])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_auto_selection_preserves_mfa_requirement(): void
    {
        [, $user] = $this->account();
        $user->mfaCredentials()->create(['type' => 'totp', 'secret_encrypted' => 'JBSWY3DPEHPK3PXP', 'verified_at' => now()]);
        $this->postJson('/api/auth/login', ['email' => 'login@example.com', 'password' => 'fixture-password'])
            ->assertStatus(202)->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_spacing_and_case_do_not_bypass_login_rate_limits(): void
    {
        $this->account();
        foreach (['login@example.com', 'LOGIN@example.com', ' login@example.com', 'login@example.com ', ' LOGIN@example.com '] as $email) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', ['email' => '  login@example.com  ', 'password' => 'wrong'])->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
