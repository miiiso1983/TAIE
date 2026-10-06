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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IntegrationGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['integration.read', 'integration.manage', 'integration.sync', 'data.import.manage', 'data.sensitive.read', 'sales.customer.create'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('i', 48), 'teami.billing_enforce' => false]);
    }

    private function actor(array $permissions = self::PERMISSIONS): array
    {
        $t = Tenant::query()->create(['name' => 'Integration Tenant', 'slug' => Str::random(10), 'status' => 'active']);
        $u = User::factory()->create(['status' => 'active']);
        $t->users()->attach($u, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $t->id, 'name' => 'Connector', 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_id' => $role->id]);
        $this->actingAs($u)->withHeaders(['X-Tenant' => $t->uuid]);
        app(TenantContext::class)->setTenant($t);

        return [$t, $u, $role];
    }

    private function source(): string
    {
        return $this->postJson('/api/integrations/connections', ['name' => 'MaxCon بغداد', 'provider' => 'maxcon'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.native_adapter_ready', false)->json('data.uuid');
    }

    private function payload(): array
    {
        return ['schema_version' => 1, 'kind' => 'customers', 'records' => [['customer_number' => 'C1', 'name' => 'عميل خاص', 'email' => 'private@example.test']]];
    }

    private function preview(string $uuid, ?array $payload = null): TestResponse
    {
        return $this->postJson('/api/integrations/connections/'.$uuid.'/preview', $payload ?? $this->payload(), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_gateway_previews_encrypts_commits_and_replays_without_duplicate_data(): void
    {
        $this->actor();
        $uuid = $this->source();
        $b = $this->preview($uuid)->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->assertSame(0, DB::table('sales_customers')->count());
        $raw = DB::table('advisor_import_batches')->where('uuid', $b)->value('payload_encrypted');
        $this->assertStringNotContainsString('private@example.test', $raw);
        $this->preview($uuid)->assertJsonPath('data.uuid', $b);
        $this->postJson('/api/advisor/imports/'.$b.'/commit', [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'imported');
        $this->preview($uuid)->assertJsonPath('data.status', 'imported');
        $this->assertSame(1, DB::table('sales_customers')->count());
        $this->assertSame(1, DB::table('advisor_import_batches')->count());
        $this->getJson('/api/integrations/connections/'.$uuid.'/batches')->assertOk()->assertJsonPath('data.data.0.row_count', 1);
    }

    public function test_disabled_source_blocks_preview_and_existing_commit_and_can_be_reenabled(): void
    {
        $this->actor();
        $uuid = $this->source();
        $b = $this->preview($uuid)->json('data.uuid');
        $this->patchJson('/api/integrations/connections/'.$uuid.'/status', ['status' => 'disabled'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->preview($uuid)->assertStatus(409);
        $this->postJson('/api/advisor/imports/'.$b.'/commit', [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(0, DB::table('sales_customers')->count());
        $this->patchJson('/api/integrations/connections/'.$uuid.'/status', ['status' => 'active'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/advisor/imports/'.$b.'/commit', [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    }

    public function test_permissions_and_foreign_tenants_cannot_sync_or_read_connections(): void
    {
        [$t, $u, $role] = $this->actor();
        $uuid = $this->source();
        $b = $this->preview($uuid)->json('data.uuid');
        $role->permissions()->detach(Permission::where('name', 'integration.sync')->first()->id);
        $this->preview($uuid)->assertForbidden();
        $this->postJson('/api/advisor/imports/'.$b.'/commit', [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->actor();
        $this->getJson('/api/integrations/connections/'.$uuid.'/batches')->assertNotFound();
        $this->preview($uuid)->assertNotFound();
        $this->getJson('/api/integrations/connections')->assertJsonCount(0, 'data.data');
        $this->assertSame(1, DB::table('integration_connections')->count());
    }

    public function test_invalid_fields_and_money_types_are_rejected_and_row_errors_are_preserved(): void
    {
        $this->actor();
        $uuid = $this->source();
        $d = $this->payload();
        $d['records'][0]['unexpected'] = 'secret';
        $this->preview($uuid, $d)->assertUnprocessable();
        $d = $this->payload();
        $d['records'][0]['customer_number'] = 1;
        $this->preview($uuid, $d)->assertUnprocessable();
        $d = $this->payload();
        unset($d['records'][0]['name']);
        $b = $this->preview($uuid, $d)->assertOk()->assertJsonPath('data.status', 'invalid')->assertJsonPath('data.error_count', 1)->json('data.uuid');
        $this->postJson('/api/advisor/imports/'.$b.'/commit', [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(0, DB::table('sales_customers')->count());
        $this->getJson('/api/integrations/catalog')->assertOk()->assertJsonCount(6, 'data');
        $this->postJson('/api/integrations/connections', ['name' => 'Other', 'provider' => 'invented'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }
}
