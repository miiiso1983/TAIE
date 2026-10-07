<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\Product;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class SourceControlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $currentTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        RateLimiter::for('imports', fn () => Limit::none());
        config(['teami.audit_hmac_key' => str_repeat('a', 48), 'teami.billing_enforce' => false]);
    }

    private function actor(array $permissions = ['data.import.manage', 'data.sensitive.read', 'data.sales.import', 'data.catalog.import', 'advisor.sales.read', 'advisor.data.review', 'advisor.source.manage', 'hr.employee.create', 'sales.customer.create', 'sales.customer.update']): array
    {
        $tenant = Tenant::query()->create(['name' => 'Import Fixture', 'slug' => 't-'.Str::random(8)]);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Importer', 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        $this->sign($tenant, $user);

        return [$tenant, $user];
    }

    private function sign(Tenant $t, User $u): void
    {
        $this->currentTenant = $t;
        $this->actingAs($u)->withHeaders(['X-Tenant' => $t->uuid]);
        app(TenantContext::class)->setTenant($t);
    }

    private function preview(string $kind, string $csv, string $source = 'primary')
    {
        return $this->post('/api/advisor/imports/preview', ['kind' => $kind, 'source_system' => $source, 'file' => UploadedFile::fake()->createWithContent('fixture.csv', $csv)], ['Accept' => 'application/json']);
    }

    private function commit(string $id)
    {
        $result = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/advisor/imports/'.$id.'/commit', []);
        app(TenantContext::class)->setTenant($this->currentTenant);

        return $result;
    }

    private function base(): void
    {
        app(TenantContext::class)->setTenant($this->currentTenant);
        Employee::query()->create(['employee_number' => 'E1', 'first_name' => 'Test', 'last_name' => 'Rep', 'uuid' => (string) Str::uuid()]);
        Customer::query()->create(['customer_number' => 'C1', 'name' => 'Customer', 'type' => 'organization', 'uuid' => (string) Str::uuid()]);
    }

    private function invoiceCsv(string $id = 'INV1', string $amount = '10.25', string $currency = 'USD'): string
    {
        return "external_id,invoice_date,due_date,customer_number,employee_number,currency,net_amount\n{$id},2026-10-01,2026-11-01,C1,E1,{$currency},{$amount}\n";
    }

    private function imported(string $kind, string $csv, string $source = 'primary'): string
    {
        $id = $this->preview($kind, $csv, $source)->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->commit($id)->assertOk();

        return $id;
    }

    private function controls(array $overrides = []): array
    {
        return [...['source_system' => 'primary', 'from' => '2026-10-01', 'to' => '2026-10-05', 'as_of' => '2026-10-06', 'currency' => 'USD', 'invoice_count' => 1, 'invoice_amount' => '10.25', 'receipt_count' => 0, 'receipt_amount' => '0', 'return_count' => 0, 'return_amount' => '0', 'evidence_reference' => 'ERP-Control-October', 'review_note' => 'Checked independent invoice cohort report', 'acknowledge' => true], ...$overrides];
    }

    private function reconcile(array $data)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/advisor/data/reconciliations', $data);
    }

    private function setupCatalog(): void
    {
        $this->actor();
        $this->base();
        $this->imported('products', "product_code,name,base_unit\nP1,Product,box\n");
        $this->imported('areas', "area_code,province_name,city_name\nA1,Baghdad,City\n");
    }

    private function send(string $path, array $d)
    {
        return $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/source-control/'.$path, [...$d, 'review_note' => 'Verified canonical source with independent report', 'acknowledge' => true]);
    }

    public function test_authority_versions_require_source_evidence_and_current_revision(): void
    {
        $this->setupCatalog();
        $this->send('authorities', ['kind' => 'invoices', 'source_system' => 'unknown'])->assertStatus(422);
        $a = $this->send('authorities', ['kind' => 'products', 'source_system' => 'primary'])->assertCreated()->json('data');
        $this->send('authorities', ['kind' => 'products', 'source_system' => 'primary'])->assertConflict();
        $this->send('authorities', ['kind' => 'products', 'source_system' => 'primary', 'previous_uuid' => $a['uuid']])->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertStringNotContainsString('independent report', DB::table('advisor_source_authorities')->value('review_note_encrypted'));
        $this->actor();
        $this->getJson('/api/advisor/source-control/authorities')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_identity_correction_retains_old_link_and_cannot_overwrite_or_cross_tenants(): void
    {
        $this->setupCatalog();
        $p = Product::query()->first();
        $d = ['kind' => 'products', 'source_system' => 'primary', 'external_id' => 'Foreign-P1', 'canonical_uuid' => $p->uuid];
        $a = $this->send('identities', $d)->assertCreated()->json('data');
        $this->send('identities', $d)->assertConflict();
        $this->send('identities/'.$a['uuid'].'/revoke', [])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->send('identities', $d)->assertConflict();
        $this->send('identities', [...$d, 'previous_uuid' => $a['uuid']])->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('advisor_identity_links', 2);
        $this->assertStringNotContainsString('Foreign-P1', DB::table('advisor_identity_links')->value('external_id_encrypted'));
        $this->actor();
        $this->send('identities', $d)->assertNotFound();
        $this->getJson('/api/advisor/source-control/identities')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_source_management_and_sensitive_identity_permissions_are_required(): void
    {
        $this->actor(['advisor.sales.read', 'data.sensitive.read']);
        $this->send('authorities', ['kind' => 'products', 'source_system' => 'primary'])->assertForbidden();
        $this->actor(['advisor.sales.read', 'data.sensitive.read', 'advisor.source.manage']);
        $this->send('identities', ['kind' => 'employees', 'source_system' => 'primary', 'external_id' => 'E1', 'canonical_uuid' => (string) Str::uuid()])->assertForbidden();
    }
}
