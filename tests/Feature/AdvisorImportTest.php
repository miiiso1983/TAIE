<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Services\CsvImportService;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class AdvisorImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('a', 48), 'teami.billing_enforce' => false]);
    }

    private function actor(array $permissions = ['data.import.manage', 'data.sensitive.read', 'data.sales.import', 'hr.employee.create', 'sales.customer.create']): array
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
        $this->actingAs($u)->withHeaders(['X-Tenant' => $t->uuid]);
        app(TenantContext::class)->setTenant($t);
    }

    private function preview(string $kind, string $csv, string $source = 'primary')
    {
        return $this->post('/api/advisor/imports/preview', ['kind' => $kind, 'source_system' => $source, 'file' => UploadedFile::fake()->createWithContent('fixture.csv', $csv)], ['Accept' => 'application/json']);
    }

    private function commit(string $id)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/advisor/imports/'.$id.'/commit', []);
    }

    private function base(): void
    {
        Employee::query()->create(['employee_number' => 'E1', 'first_name' => 'Test', 'last_name' => 'Rep', 'uuid' => (string) Str::uuid()]);
        Customer::query()->create(['customer_number' => 'C1', 'name' => 'Customer', 'type' => 'organization', 'uuid' => (string) Str::uuid()]);
    }

    private function invoiceCsv(string $id = 'INV1', string $amount = '10.25', string $currency = 'USD'): string
    {
        return "external_id,invoice_date,due_date,customer_number,employee_number,currency,net_amount\n{$id},2026-10-01,2026-11-01,C1,E1,{$currency},{$amount}\n";
    }

    public function test_employee_preview_is_encrypted_and_commit_is_explicit_atomic_and_replay_safe(): void
    {
        $this->actor();
        $csv = "employee_number,first_name,last_name,work_email,hired_at\nE2,Private,Person,p@example.test,2026-01-01\n";
        $b = $this->preview('employees', $csv)->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->assertDatabaseCount('hr_employees', 0);
        $raw = DB::table('advisor_import_batches')->value('payload_encrypted');
        $this->assertStringNotContainsString('Private', $raw);
        $this->assertStringNotContainsString('p@example.test', $raw);
        $this->commit($b)->assertOk()->assertJsonPath('data.status', 'imported');
        $this->commit($b)->assertOk();
        $this->assertDatabaseCount('hr_employees', 1);
        $this->assertDatabaseHas('advisor_import_batches', ['uuid' => $b, 'payload_encrypted' => null]);
        $this->preview('employees', $csv)->assertOk()->assertJsonPath('data.status', 'imported');
        $this->assertDatabaseCount('hr_employees', 1);
    }

    public function test_one_invalid_row_prevents_all_writes(): void
    {
        $this->actor();
        $csv = "employee_number,first_name,last_name,work_email,hired_at\nE1,One,Person,,2026-01-01\nE1,Other,Person,,2026-01-01\n";
        $b = $this->preview('employees', $csv)->assertOk()->assertJsonPath('data.status', 'invalid')->json('data.uuid');
        $this->commit($b)->assertConflict();
        $this->assertDatabaseCount('hr_employees', 0);
    }

    public function test_financial_import_preserves_minor_units_links_and_receipt_totals(): void
    {
        $this->actor();
        $this->base();
        $b = $this->preview('invoices', $this->invoiceCsv())->assertOk()->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->assertDatabaseHas('advisor_sales_invoices', ['external_id' => 'INV1', 'net_amount_minor' => 1025, 'currency' => 'USD']);
        $header = "external_id,invoice_external_id,receipt_date,currency,amount\n";
        $b = $this->preview('receipts', $header."R1,INV1,2026-10-02,USD,5.25\nR2,INV1,2026-10-03,USD,5.00\n")->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->assertDatabaseCount('advisor_sales_receipts', 2);
        $this->preview('receipts', $header."R3,INV1,2026-10-04,USD,0.01\n")->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->preview('receipts', $header."R4,INV1,2026-10-04,IQD,1.000\n")->assertOk()->assertJsonPath('data.status', 'invalid');
    }

    public function test_overpayment_across_file_rows_and_invalid_decimals_are_rejected(): void
    {
        $this->actor();
        $this->base();
        $b = $this->preview('invoices', $this->invoiceCsv())->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->preview('receipts', "external_id,invoice_external_id,receipt_date,currency,amount\nR1,INV1,2026-10-02,USD,8.00\nR2,INV1,2026-10-02,USD,8.00\n")->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->assertDatabaseCount('advisor_sales_receipts', 0);
        $this->preview('invoices', $this->invoiceCsv('INV2', '1.001'))->assertOk()->assertJsonPath('data.status', 'invalid');
        $b = $this->preview('invoices', $this->invoiceCsv('IQD1', '2.123', 'IQD'))->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->assertDatabaseHas('advisor_sales_invoices', ['external_id' => 'IQD1', 'net_amount_minor' => 2123]);
    }

    public function test_other_tenant_batch_and_foreign_references_are_not_accessible(): void
    {
        [$t,$u] = $this->actor();
        $this->base();
        $b = $this->preview('invoices', $this->invoiceCsv())->json('data.uuid');
        $this->actor();
        $this->commit($b)->assertNotFound();
        $this->preview('invoices', $this->invoiceCsv())->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->getJson('/api/advisor/imports')->assertOk()->assertJsonCount(1, 'data.data');
        $this->assertDatabaseCount('advisor_sales_invoices', 0);
    }

    public function test_permissions_expiry_and_changed_data_are_rechecked(): void
    {
        [$t,$u] = $this->actor();
        $csv = "customer_number,name,email\nC1,One,\n";
        $b = $this->preview('customers', $csv)->json('data.uuid');
        $this->sign($t, $u);
        Customer::query()->create(['uuid' => (string) Str::uuid(), 'customer_number' => 'C1', 'name' => 'Existing']);
        $this->commit($b)->assertConflict();
        $this->assertDatabaseCount('sales_customers', 1);
        $b = $this->preview('customers', "customer_number,name,email\nC2,Two,\n")->json('data.uuid');
        $this->sign($t, $u);
        ImportBatch::query()->where('uuid', $b)->update(['expires_at' => now()->subMinute()]);
        $this->commit($b)->assertConflict();
        $this->actor(['data.import.manage']);
        $this->getJson('/api/advisor/imports/schemas')->assertForbidden();
        $this->preview('customers', $csv)->assertForbidden();
        $this->actor(['data.import.manage', 'data.sensitive.read']);
        $this->preview('customers', $csv)->assertForbidden();
    }

    public function test_schema_limits_and_utf8_bom_are_handled(): void
    {
        $this->actor();
        $this->preview('employees', "wrong,header\n1,2\n")->assertUnprocessable();
        $this->preview('employees', "employee_number,first_name,last_name,work_email,hired_at\n")->assertUnprocessable();
        $this->preview('customers', "\xEF\xBB\xBFcustomer_number,name,email\nC1,عميل تجريبي,\n")->assertOk()->assertJsonPath('data.status', 'validated');
        $rows = "customer_number,name,email\n";
        for ($i = 0; $i < 1001; $i++) {
            $rows .= "C{$i},Test,\n";
        }$this->preview('customers', $rows)->assertUnprocessable();
    }

    public function test_expired_subscription_blocks_import_writes(): void
    {
        $this->actor();
        config(['teami.billing_enforce' => true]);
        $this->preview('customers', "customer_number,name,email\nC1,Test,\n")->assertForbidden();
        $this->getJson('/api/advisor/imports')->assertOk();
    }

    private function excelFile(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'teami_xlsx_');
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($sheets as $index => $rows) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }$writer->getCurrentSheet()->setName('Data '.($index + 1));
            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(fn ($v) => $v instanceof Cell ? $v : Cell::fromValue($v, $v instanceof \DateTimeInterface ? (new Style)->setFormat('yyyy-mm-dd') : null), $row)));
            }
        }
        $writer->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('fixture.xlsx', $bytes);
    }

    private function mappedPreview(string $kind, UploadedFile $file, array $mapping = [], int $sheet = 0)
    {
        return $this->post('/api/advisor/imports/preview', ['kind' => $kind, 'source_system' => 'primary', 'file' => $file, 'sheet' => $sheet, 'mapping' => json_encode($mapping)], ['Accept' => 'application/json']);
    }

    public function test_excel_sheet_selection_column_mapping_and_optional_missing_fields(): void
    {
        $this->actor();
        $sheets = [[['Ignored'], ['Other']], [['اسم العميل', 'رمز العميل', 'غير مطلوب'], ['عميل تجريبي', 'C-XLSX', 'Extra']]];
        $this->post('/api/advisor/imports/inspect', ['kind' => 'customers', 'file' => $this->excelFile($sheets)], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'data.sheets')->assertJsonPath('data.sheets.1.columns.0', 'اسم العميل');
        $b = $this->mappedPreview('customers', $this->excelFile($sheets), ['customer_number' => 'رمز العميل', 'name' => 'اسم العميل', 'email' => ''], 1)->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->assertDatabaseHas('sales_customers', ['customer_number' => 'C-XLSX', 'name' => 'عميل تجريبي', 'email' => null]);
    }

    public function test_csv_mapping_duplicate_header_and_duplicate_bindings_are_rejected(): void
    {
        $this->actor();
        $file = fn () => UploadedFile::fake()->createWithContent('data.csv', "Company,Code,Unused\nName,C1,Extra\n");
        $this->mappedPreview('customers', $file(), ['customer_number' => 'Code', 'name' => 'Code', 'email' => ''])->assertUnprocessable();
        $this->mappedPreview('customers', $file(), ['customer_number' => 'Code', 'name' => 'Company', 'email' => ''])->assertOk()->assertJsonPath('data.status', 'validated');
        $this->mappedPreview('customers', UploadedFile::fake()->createWithContent('data.csv', "Code,Code\nA,B\n"), ['customer_number' => 'Code', 'name' => 'Code', 'email' => ''])->assertUnprocessable();
    }

    public function test_excel_date_and_numeric_amount_match_csv_and_deduplicate(): void
    {
        [$t,$u] = $this->actor();
        $this->base();
        $head = CsvImportService::HEADERS['invoices'];
        $file = $this->excelFile([[$head, ['INV1', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-01'), 'C1', 'E1', 'USD', 10.25]]]);
        $b = $this->mappedPreview('invoices', $file)->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->preview('invoices', $this->invoiceCsv())->assertOk()->assertJsonPath('data.status', 'imported');
        $this->assertDatabaseCount('advisor_sales_invoices', 1);
    }

    public function test_excel_formula_external_xml_entities_and_archive_traversal_fail_closed(): void
    {
        $this->actor();
        $head = ['customer_number', 'name', 'email'];
        $file = $this->excelFile([[$head, ['C1', new FormulaCell('="Company"', null, 'Company'), '']]]);
        $this->mappedPreview('customers', $file)->assertUnprocessable();
        foreach (['dtd', 'external', 'traversal', 'bomb'] as $attack) {
            $file = $this->excelFile([[$head, ['C1', 'Company', '']]]);
            $zip = new \ZipArchive;
            $zip->open($file->getRealPath());
            if ($attack === 'dtd') {
                $zip->addFromString('evil.xml', '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>');
            }
            if ($attack === 'external') {
                $zip->addFromString('evil.rels', '<Relationships><Relationship TargetMode="External" Target="https://example.test"/></Relationships>');
            }
            if ($attack === 'traversal') {
                $zip->addFromString('../evil.xml', '<x/>');
            }
            if ($attack === 'bomb') {
                $zip->addFromString('xl/large.xml', '<x>'.str_repeat('a', 2 * 1024 * 1024).'</x>');
            }
            $zip->close();
            $this->mappedPreview('customers', $file)->assertUnprocessable();
        }
        $this->assertDatabaseCount('advisor_import_batches', 0);
    }

    private function metricsUrl(string $path = 'metrics', string $extra = ''): string
    {
        return '/api/advisor/sales/'.$path.'?from=2026-09-01&to=2026-09-30&as_of=2026-10-05'.$extra;
    }

    private function financialFixture(): array
    {
        [$t,$u] = $this->actor(['advisor.sales.read', 'data.sensitive.read']);
        $this->base();
        $employee = Employee::query()->first();
        $customer = Customer::query()->first();
        $batch = ImportBatch::query()->create(['uuid' => (string) Str::uuid(), 'kind' => 'invoices', 'source_system' => 'primary', 'content_hash' => hash('sha256', 'metrics'), 'status' => 'imported', 'row_count' => 3, 'created_by' => $u->id, 'expires_at' => now()->addDays(7), 'committed_at' => now()]);
        $invoices = [];
        foreach ([['I1', 'USD', 10000, '2026-09-15', '2026-10-01'], ['I2', 'IQD', 1000000, '2026-09-20', '2026-10-05'], ['Outside', 'USD', 5000, '2026-08-01', '2026-09-01']] as [$id,$currency,$amount,$date,$due]) {
            $invoices[$id] = SalesInvoice::query()->create(['uuid' => (string) Str::uuid(), 'batch_id' => $batch->id, 'customer_id' => $customer->id, 'employee_id' => $employee->id, 'source_system' => 'primary', 'external_id' => $id, 'invoice_date' => $date, 'due_date' => $due, 'currency' => $currency, 'net_amount_minor' => $amount]);
        }
        foreach ([['R1', 'I1', 2000, '2026-09-25'], ['R2', 'I1', 3000, '2026-10-02'], ['Future', 'I1', 1000, '2026-10-06'], ['R3', 'I2', 100000, '2026-10-02']] as [$id,$invoice,$amount,$date]) {
            SalesReceipt::query()->create(['uuid' => (string) Str::uuid(), 'batch_id' => $batch->id, 'invoice_id' => $invoices[$invoice]->id, 'source_system' => 'primary', 'external_id' => $id, 'receipt_date' => $date, 'currency' => $invoices[$invoice]->currency, 'amount_minor' => $amount]);
        }

        return [$t, $u, $employee];
    }

    public function test_metrics_use_invoice_cohort_as_of_cutoff_and_separate_currencies_with_evidence(): void
    {
        $this->financialFixture();
        $r = $this->getJson($this->metricsUrl())->assertOk()->assertJsonPath('data.quality.coverage_confirmed', false)->assertJsonPath('data.quality.invoice_count', 2)->assertJsonPath('data.quality.recorded_receipt_count', 3)->json('data.rows');
        $usd = collect($r)->firstWhere('currency', 'USD');
        $iqd = collect($r)->firstWhere('currency', 'IQD');
        $this->assertSame('10000', $usd['sales_minor']);
        $this->assertSame('5000', $usd['collected_minor']);
        $this->assertSame('5000', $usd['overdue_balance_minor']);
        $this->assertSame('50.00', $usd['collection_percent']);
        $this->assertSame('0', $iqd['overdue_balance_minor']);
        $this->getJson($this->metricsUrl('invoices', '&currency=USD'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.external_id', 'I1')->assertJsonPath('data.0.collected_minor', '5000');
    }

    public function test_metrics_permission_tenant_empty_and_invalid_date_guards(): void
    {
        [$t,$u,$employee] = $this->financialFixture();
        $foreign = $employee->uuid;
        $this->actor(['advisor.sales.read', 'data.sensitive.read']);
        $this->getJson($this->metricsUrl())->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson($this->metricsUrl('invoices', '&employee_uuid='.$foreign))->assertNotFound();
        $this->getJson('/api/advisor/sales/metrics?from=2026-09-30&to=2026-09-01&as_of=2026-10-05')->assertUnprocessable();
        $this->getJson('/api/advisor/sales/metrics?from=2026-09-01&to=2026-09-30&as_of=2099-01-01')->assertUnprocessable();
        $this->actor(['advisor.sales.read']);
        $this->getJson($this->metricsUrl())->assertForbidden();
        $this->actor(['data.sensitive.read']);
        $this->getJson($this->metricsUrl())->assertForbidden();
    }
}
