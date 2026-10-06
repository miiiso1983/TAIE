<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Services\RecordReconciliationService;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class RecordReconciliationTest extends TestCase
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

    private function evidence(string $rows, array $override = [], ?string $key = null)
    {
        $data = array_intersect_key($this->controls($override), array_flip(['source_system', 'from', 'to', 'as_of', 'currency', 'evidence_reference', 'review_note', 'acknowledge']));
        $data['file'] = UploadedFile::fake()->createWithContent('evidence.csv', implode(',', RecordReconciliationService::HEADERS)."\n".$rows);

        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->post('/api/advisor/data/reconciliations/detailed', $data, ['Accept' => 'application/json']);
    }

    private function row(string $id = 'INV1', string $amount = '10.25', string $currency = 'USD'): string
    {
        return "invoices,{$id},,2026-10-01,2026-11-01,C1,E1,{$currency},{$amount}\n";
    }

    public function test_equal_totals_cannot_hide_missing_and_unexpected_identities(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        $r = $this->evidence($this->row('OTHER'))->assertCreated()->assertJsonPath('data.method', 'record_matching')->assertJsonPath('data.status', 'mismatched')->assertJsonPath('data.snapshot.totals_status', 'matched')->assertJsonPath('data.snapshot.record_counts.missing_in_teami', 1)->assertJsonPath('data.snapshot.record_counts.unexpected_in_teami', 1)->assertJsonPath('data.coverage_confirmed', false);
        $this->assertArrayNotHasKey('expected_records', $r->json('data.snapshot'));
        $this->assertArrayNotHasKey('records', $r->json('data.current'));
        $this->getJson('/api/advisor/data/reconciliations/'.$r->json('data.uuid').'/differences?type=missing_in_teami')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.external_id', 'OTHER');
        $this->assertDatabaseCount('advisor_sales_invoices', 1);
    }

    public function test_changed_scope_currency_date_amount_and_parent_are_field_differences(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        $this->imported('invoices', $this->invoiceCsv('INV2'));
        $this->imported('receipts', "external_id,invoice_external_id,receipt_date,currency,amount\nR1,INV2,2026-10-06,USD,1.00\n");
        DB::table('advisor_sales_invoices')->where('external_id', 'INV1')->update(['currency' => 'IQD', 'invoice_date' => '2026-09-30']);
        $r = $this->evidence($this->row().$this->row('INV2')."receipts,R1,INV1,2026-10-02,,,,USD,2.00\n")->assertCreated()->assertJsonPath('data.snapshot.record_counts.changed_fields', 2)->assertJsonPath('data.snapshot.record_counts.missing_in_teami', 0);
        $this->getJson('/api/advisor/data/reconciliations/'.$r->json('data.uuid').'/differences')->assertOk()->assertJsonPath('data.data.0.fields.currency.imported', 'IQD')->assertJsonPath('data.data.0.fields.record_date.imported', '2026-09-30')->assertJsonPath('data.data.1.fields.invoice_external_id.imported', 'INV2')->assertJsonPath('data.data.1.fields.amount_minor.imported', '100');
    }

    public function test_matching_evidence_is_encrypted_idempotent_and_stale_after_change(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv('0001', '100.125', 'IQD'));
        $rows = $this->row('0001', '100.125', 'IQD');
        $key = (string) Str::uuid();
        $r = $this->evidence($rows, ['currency' => 'IQD'], $key)->assertCreated()->assertJsonPath('data.current_status', 'matched')->assertJsonPath('data.snapshot.record_counts.matched', 1)->assertJsonPath('data.snapshot.expected.invoices.amount_minor', '100125');
        $uuid = $r->json('data.uuid');
        $this->evidence($rows, ['currency' => 'IQD'], $key)->assertCreated()->assertJsonPath('data.uuid', $uuid);
        $this->evidence($this->row('0001', '100.126', 'IQD'), ['currency' => 'IQD'], $key)->assertConflict();
        $this->assertDatabaseCount('advisor_reconciliations', 1);
        $raw = DB::table('advisor_reconciliations')->first();
        $this->assertStringNotContainsString('0001', $raw->snapshot_encrypted);
        $this->assertStringNotContainsString('expected_records', $raw->snapshot_encrypted);
        DB::table('advisor_sales_invoices')->where('external_id', '0001')->update(['net_amount_minor' => 100126]);
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid)->assertOk()->assertJsonPath('data.current_status', 'stale')->assertJsonPath('data.snapshot.actual.invoices.amount_minor', '100125')->assertJsonPath('data.current.actual.invoices.amount_minor', '100126');
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid.'/differences')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_invalid_report_is_atomic_and_requires_exact_scope_and_parent(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        foreach ([$this->row().$this->row(), $this->row('INV1', '10.251'), $this->row('INV1', '10.25', 'IQD'), str_replace('2026-10-01', '2026-09-30', $this->row()), $this->row()."receipts,R1,OTHER,2026-10-02,,,,USD,1.00\n", $this->row()."receipts,R1,INV1,2026-10-07,,,,USD,1.00\n"] as $rows) {
            $this->evidence($rows)->assertUnprocessable();
        }
        $this->evidence($this->row(), ['acknowledge' => false])->assertUnprocessable();
        $this->assertDatabaseCount('advisor_reconciliations', 0);
        $this->assertDatabaseCount('advisor_sales_invoices', 1);
    }

    public function test_read_review_and_tenant_boundaries_apply_to_evidence_and_differences(): void
    {
        [$tenant,$user] = $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        $uuid = $this->evidence($this->row('OTHER'))->assertCreated()->json('data.uuid');
        $this->actor(['advisor.sales.read', 'data.sensitive.read']);
        $this->evidence($this->row())->assertForbidden();
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid.'/differences')->assertNotFound();
        $this->actor(['advisor.sales.read']);
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid.'/differences')->assertForbidden();
        $this->sign($tenant, $user);
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid.'/differences?kind=unknown')->assertUnprocessable();
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid.'/differences?page=0')->assertUnprocessable();
    }

    public function test_paginated_differences_and_customer_codes_are_preserved_in_saved_evidence(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        $rows = '';
        for ($i = 0; $i < 55; $i++) {
            $rows .= $this->row('EXT'.$i);
        }
        $uuid = $this->evidence($rows)->assertCreated()->json('data.uuid');
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid.'/differences')->assertOk()->assertJsonPath('data.total', 56)->assertJsonCount(50, 'data.data');
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid.'/differences?page=2')->assertOk()->assertJsonCount(6, 'data.data');
        DB::table('sales_customers')->update(['customer_number' => 'C2']);
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid)->assertOk()->assertJsonPath('data.current_status', 'stale');
        $this->getJson('/api/advisor/data/reconciliations/'.$uuid.'/differences?type=unexpected_in_teami')->assertOk()->assertJsonPath('data.data.0.imported.customer_number', 'C1');
    }

    public function test_source_xlsx_and_movements_after_period_end_match_exact_minor_units(): void
    {
        $this->actor();
        $this->base();
        $this->imported('invoices', $this->invoiceCsv());
        $this->imported('receipts', "external_id,invoice_external_id,receipt_date,currency,amount\nR1,INV1,2026-10-06,USD,0.01\n");
        $this->imported('returns', "external_id,invoice_external_id,return_date,currency,amount\nT1,INV1,2026-10-03,USD,0.02\n");
        $path = tempnam(sys_get_temp_dir(), 'evidence_');
        $writer = new Writer;
        $writer->openToFile($path);
        $rows = [RecordReconciliationService::HEADERS, ['invoices', 'INV1', '', '2026-10-01', '2026-11-01', 'C1', 'E1', 'USD', '10.25'], ['receipts', 'R1', 'INV1', '2026-10-06', '', '', '', 'USD', '0.01'], ['returns', 'T1', 'INV1', '2026-10-03', '', '', '', 'USD', '0.02']];
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        $data = array_intersect_key($this->controls(), array_flip(['source_system', 'from', 'to', 'as_of', 'currency', 'evidence_reference', 'review_note', 'acknowledge']));
        $data['file'] = UploadedFile::fake()->createWithContent('report.xlsx', file_get_contents($path));
        unlink($path);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->post('/api/advisor/data/reconciliations/detailed', $data, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.snapshot.record_counts.matched', 3)->assertJsonPath('data.snapshot.actual.receipts.amount_minor', '1')->assertJsonPath('data.snapshot.actual.returns.amount_minor', '2');
    }
}
