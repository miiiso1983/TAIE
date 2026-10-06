<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryInputsTest extends TestCase
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

    private function actor(array $permissions = ['data.import.manage', 'data.sensitive.read', 'data.sales.import', 'data.catalog.import', 'advisor.sales.read', 'advisor.data.review', 'hr.employee.create', 'sales.customer.create', 'sales.customer.update']): array
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

    public function test_inventory_cost_and_stockouts_preserve_units_and_precision(): void
    {
        $this->setupCatalog();
        $this->imported('stock_snapshots', "external_id,product_code,area_code,snapshot_date,quantity\nS1,P1,A1,2026-09-01,0\nS2,P1,A1,2026-09-02,2.125\n");
        $this->imported('product_costs', "external_id,product_code,effective_date,currency,unit_cost\nC1,P1,2026-09-01,IQD,1200.125\n");
        $this->imported('stockouts', "external_id,product_code,area_code,from_date,to_date\nO1,P1,A1,2026-09-01,2026-09-02\n");
        $this->getJson('/api/advisor/data/inventory?kind=product_costs')->assertOk()->assertJsonPath('data.data.0.amount_minor', '1200125');
        $this->assertDatabaseHas('advisor_inventory_observations', ['external_id' => 'S2', 'quantity' => '2.125']);
        $bad = $this->preview('stock_snapshots', "external_id,product_code,area_code,snapshot_date,quantity\nS3,P1,A1,2026-09-03,-1\nS4,P1,A1,2026-09-03,2.0001\n")->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->assertDatabaseCount('advisor_inventory_observations', 4);
    }

    public function test_return_lines_cannot_exceed_sold_quantity_or_return_value_and_do_not_double_money(): void
    {
        $this->setupCatalog();
        $this->imported('invoices', $this->invoiceCsv());
        $this->imported('invoice_lines', "external_id,invoice_external_id,product_code,quantity,net_amount\nL1,INV1,P1,3,10.25\n");
        $this->imported('returns', "external_id,invoice_external_id,return_date,currency,amount\nR1,INV1,2026-10-02,USD,2.00\nR2,INV1,2026-10-03,USD,1.00\n");
        $this->imported('return_lines', "external_id,return_external_id,invoice_line_external_id,quantity,amount\nRL1,R1,L1,1.250,2.00\n");
        $this->preview('return_lines', "external_id,return_external_id,invoice_line_external_id,quantity,amount\nRL2,R2,L1,2.000,1.00\n")->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->preview('return_lines', "external_id,return_external_id,invoice_line_external_id,quantity,amount\nRL3,R1,L1,0.250,0.01\n")->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->getJson('/api/advisor/sales/metrics?from=2026-10-01&to=2026-10-05&as_of=2026-10-06')->assertOk()->assertJsonPath('data.rows.0.returns_minor', '300');
    }

    public function test_inventory_import_isolated_atomic_idempotent_and_revalidates_at_commit(): void
    {
        $this->setupCatalog();
        $csv = "external_id,product_code,area_code,snapshot_date,quantity\nS1,P1,A1,2026-09-01,1\n";
        $id = $this->preview('stock_snapshots', $csv)->assertOk()->json('data.uuid');
        $this->imported('stock_snapshots', str_replace(",1\n", ",2\n", $csv));
        $this->commit($id)->assertStatus(409);
        $this->actor();
        $this->preview('stock_snapshots', $csv)->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->getJson('/api/advisor/data/inventory?kind=stock_snapshots')->assertOk()->assertJsonCount(0, 'data.data');
    }
}
