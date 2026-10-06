<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\InvoiceLine;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataQualityFoundationTest extends TestCase
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

    public function test_product_area_lines_import_preserves_invoice_amounts_units_and_historical_area(): void
    {
        $this->actor();
        $this->base();
        $this->imported('products', "product_code,name,base_unit\nP1,Product,box\n");
        $this->imported('areas', "area_code,province_name,city_name\nBGH1,بغداد,الكرادة\n");
        $this->imported('invoices', $this->invoiceCsv('BEFORE'));
        $this->imported('customer_locations', "customer_number,area_code\nC1,BGH1\n");
        $this->imported('invoices', $this->invoiceCsv('AFTER'));
        $this->assertNull(SalesInvoice::query()->where('external_id', 'BEFORE')->first()->area_id);
        $this->assertSame(Area::query()->first()->id, SalesInvoice::query()->where('external_id', 'AFTER')->first()->area_id);
        $id = $this->imported('invoice_lines', "external_id,invoice_external_id,product_code,quantity,net_amount\nL1,AFTER,P1,2.500,10.25\nL2,AFTER,P1,1,0.00\n");
        $this->commit($id)->assertOk();
        $this->assertDatabaseCount('advisor_invoice_lines', 2);
        $line = InvoiceLine::query()->where('external_id', 'L1')->first();
        $this->assertSame('2.500', $line->quantity);
        $this->assertSame('box', $line->base_unit);
        $this->assertSame(1025, $line->net_amount_minor);
        $this->getJson('/api/advisor/data/catalog?kind=invoice_lines')->assertOk()->assertJsonPath('data.data.0.net_amount_minor', '1025');
        $this->getJson('/api/advisor/data/catalog?kind=customer_locations')->assertOk()->assertJsonPath('data.data.0.province_name', 'بغداد');
        $this->getJson('/api/advisor/sales/metrics?from=2026-10-01&to=2026-10-05&as_of=2026-10-06')->assertOk()->assertJsonPath('data.rows.0.sales_minor', '2050');
        $r = $this->reconcile($this->controls(['invoice_count' => 2, 'invoice_amount' => '20.50']))->assertCreated()->assertJsonPath('data.status', 'matched')->assertJsonPath('data.current.readiness.invoices_without_area', 1)->assertJsonPath('data.current.readiness.invoices_without_lines', 1);
        $this->assertFalse($r->json('data.coverage_confirmed'));
    }

    public function test_invalid_lines_are_atomic_and_commit_rechecks_total_and_parent_source(): void
    {
        $this->actor();
        $this->base();
        $this->imported('products', "product_code,name,base_unit\nP1,Product,box\n");
        $this->imported('invoices', $this->invoiceCsv());
        $b = $this->preview('invoice_lines', "external_id,invoice_external_id,product_code,quantity,net_amount\nL1,INV1,P1,1,10.25\nL2,INV1,P1,1,0.01\n")->assertOk()->assertJsonPath('data.status', 'invalid')->json('data.uuid');
        $this->commit($b)->assertConflict();
        $this->assertDatabaseCount('advisor_invoice_lines', 0);
        $this->preview('invoice_lines', "external_id,invoice_external_id,product_code,quantity,net_amount\nL0,INV1,P1,0,1.00\n")->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->preview('invoice_lines', "external_id,invoice_external_id,product_code,quantity,net_amount\nL0,INV1,P1,1,1.00\n", 'other')->assertOk()->assertJsonPath('data.status', 'invalid');
        $pending = $this->preview('invoice_lines', "external_id,invoice_external_id,product_code,quantity,net_amount\nPENDING,INV1,P1,1,10.25\n")->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->imported('invoice_lines', "external_id,invoice_external_id,product_code,quantity,net_amount\nFIRST,INV1,P1,1,1.00\n");
        $this->commit($pending)->assertConflict();
        $this->assertDatabaseCount('advisor_invoice_lines', 1);
        $this->reconcile($this->controls())->assertCreated()->assertJsonPath('data.current.readiness.invoices_with_line_difference', 1);
    }

    public function test_reconciliation_reports_signed_differences_encryption_and_idempotency(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        $this->imported('receipts', "external_id,invoice_external_id,receipt_date,currency,amount\nR1,INV1,2026-10-06,USD,5.00\n");
        $this->imported('returns', "external_id,invoice_external_id,return_date,currency,amount\nT1,INV1,2026-10-02,USD,2.00\n");
        $body = $this->controls(['invoice_count' => 2, 'invoice_amount' => '15.25', 'receipt_count' => 1, 'receipt_amount' => '4.00', 'return_count' => 1, 'return_amount' => '2.00']);
        $key = (string) Str::uuid();
        $r = $this->withHeader('Idempotency-Key', $key)->postJson('/api/advisor/data/reconciliations', $body)->assertCreated()->assertJsonPath('data.status', 'mismatched')->assertJsonPath('data.snapshot.differences.invoices.count', -1)->assertJsonPath('data.snapshot.differences.invoices.amount_minor', '-500')->assertJsonPath('data.snapshot.differences.receipts.amount_minor', '100')->assertJsonPath('data.snapshot.differences.returns.amount_minor', '0');
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/advisor/data/reconciliations', $body)->assertCreated()->assertJsonPath('data.uuid', $r->json('data.uuid'));
        $this->assertDatabaseCount('advisor_reconciliations', 1);
        $raw = DB::table('advisor_reconciliations')->first();
        $this->assertStringNotContainsString('ERP-Control', $raw->evidence_reference_encrypted);
        $this->assertStringNotContainsString('independent', $raw->review_note_encrypted);
        $this->assertStringNotContainsString('expected', $raw->snapshot_encrypted);
        $this->reconcile($this->controls(['receipt_count' => 1, 'receipt_amount' => '5.00', 'return_count' => 1, 'return_amount' => '2.00']))->assertCreated()->assertJsonPath('data.current_status', 'matched');
    }

    public function test_saved_comparison_becomes_stale_only_when_its_financial_scope_changes(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        $uuid = $this->reconcile($this->controls())->assertCreated()->json('data.uuid');
        $this->imported('receipts', "external_id,invoice_external_id,receipt_date,currency,amount\nFUTURE,INV1,2026-10-07,USD,1.00\n");
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid)->assertOk()->assertJsonPath('data.current_status', 'matched');
        $this->imported('receipts', "external_id,invoice_external_id,receipt_date,currency,amount\nCURRENT,INV1,2026-10-03,USD,1.00\n");
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid)->assertOk()->assertJsonPath('data.current_status', 'stale')->assertJsonPath('data.snapshot.actual.receipts.count', 0)->assertJsonPath('data.current.actual.receipts.count', 1);
        $this->assertSame('matched', DB::table('advisor_reconciliations')->value('status'));
    }

    public function test_readers_cannot_review_and_foreign_tenant_catalog_source_or_report_is_hidden(): void
    {
        [$tenant,$user] = $this->actor();
        $this->base();
        $this->imported('products', "product_code,name,base_unit\nP1,Private product,box\n");
        $this->imported('invoices', $this->invoiceCsv());
        $uuid = $this->reconcile($this->controls())->assertCreated()->json('data.uuid');
        $this->actor(['advisor.sales.read', 'data.sensitive.read']);
        $this->getJson('/api/advisor/data/catalog?kind=products')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/advisor/data/sources')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid)->assertNotFound();
        $this->reconcile($this->controls())->assertForbidden();
        $this->actor();
        $this->reconcile($this->controls())->assertNotFound();
        $this->base();
        $this->preview('invoice_lines', "external_id,invoice_external_id,product_code,quantity,net_amount\nL1,INV1,P1,1,1.00\n")->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->sign($tenant, $user);
        $this->getJson('/api/advisor/data/catalog?kind=products')->assertOk()->assertJsonPath('data.data.0.name', 'Private product');
    }

    public function test_scope_confirmation_amount_precision_and_permissions_are_enforced(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        $this->reconcile($this->controls(['acknowledge' => false]))->assertUnprocessable();
        $this->reconcile($this->controls(['invoice_amount' => '10.251']))->assertUnprocessable();
        $this->reconcile($this->controls(['to' => '2026-09-30']))->assertUnprocessable();
        $this->reconcile($this->controls(['invoice_amount' => '100000000000000000.00']))->assertCreated()->assertJsonPath('data.status', 'mismatched');
        $this->actor(['data.import.manage', 'data.sensitive.read', 'data.sales.import']);
        $this->preview('products', "product_code,name,base_unit\nP1,Product,box\n")->assertForbidden();
        $this->getJson('/api/advisor/imports/schemas')->assertOk()->assertJsonMissing(['kind' => 'products']);
    }

    public function test_controls_keep_source_currency_and_invoice_cohort_separate_with_iqd_precision(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv('IQ1', '100.125', 'IQD'));
        $this->imported('invoices', $this->invoiceCsv('USD1', '10.25', 'USD'));
        $this->imported('invoices', $this->invoiceCsv('OTHER', '999.000', 'IQD'), 'other');
        $this->imported('receipts', "external_id,invoice_external_id,receipt_date,currency,amount\nR1,IQ1,2026-10-06,IQD,0.125\n");
        $this->imported('returns', "external_id,invoice_external_id,return_date,currency,amount\nT1,IQ1,2026-10-03,IQD,0.100\n");
        $this->reconcile($this->controls(['currency' => 'IQD', 'invoice_amount' => '100.125', 'receipt_count' => 1, 'receipt_amount' => '0.125', 'return_count' => 1, 'return_amount' => '0.100']))->assertCreated()->assertJsonPath('data.current_status', 'matched')->assertJsonPath('data.snapshot.actual.invoices.amount_minor', '100125')->assertJsonPath('data.snapshot.actual.receipts.amount_minor', '125')->assertJsonPath('data.snapshot.actual.returns.amount_minor', '100');
        $this->reconcile($this->controls(['from' => '2026-09-01', 'to' => '2026-09-30', 'invoice_count' => 0, 'invoice_amount' => '0']))->assertCreated()->assertJsonPath('data.current_status', 'matched')->assertJsonPath('data.current.readiness.invoice_count', 0)->assertJsonPath('data.coverage_confirmed', false);
    }
}
