<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\CoverageConfirmation;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Models\SalesReturn;
use App\Modules\Advisor\Models\SalesTarget;
use App\Modules\Advisor\Services\BusinessDataImportService;
use App\Modules\Advisor\Services\CsvImportService;
use App\Modules\HR\Models\CompensationRecord;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvisorQualityTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['advisor.bonus.read', 'advisor.bonus.submit', 'advisor.bonus.approve', 'advisor.bonus.override', 'advisor.policy.manage', 'advisor.sales.read', 'data.sensitive.read', 'hr.compensation.read', 'hr.compensation.write', 'data.import.manage', 'data.sales.import', 'sales.target.write'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('b', 48), 'teami.billing_enforce' => false]);
        $this->travelTo(now()->setDate(2026, 10, 5));
    }

    private function actor(?Tenant $tenant = null, array $permissions = self::PERMISSIONS): array
    {
        $tenant ??= Tenant::query()->create(['name' => 'Advisor Test', 'slug' => 'test-'.Str::random(8)]);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Role '.Str::random(6), 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        $this->sign($tenant, $user);

        return [$tenant, $user];
    }

    private function sign(Tenant $tenant, User $user): void
    {
        $this->actingAs($user)->withHeaders(['X-Tenant' => $tenant->uuid]);
        app(TenantContext::class)->setTenant($tenant);
    }

    private function fixture(): array
    {
        [$t, $u] = $this->actor();
        $employee = Employee::query()->create(['employee_number' => 'E1', 'first_name' => 'Test', 'last_name' => 'Rep', 'employment_status' => 'active']);
        $customer = Customer::query()->create(['customer_number' => 'C1', 'name' => 'Test Customer']);
        $batch = ImportBatch::query()->create(['uuid' => Str::uuid(), 'kind' => 'invoices', 'source_system' => 'primary', 'content_hash' => hash('sha256', 'test'), 'status' => 'imported', 'row_count' => 1, 'created_by' => $u->id, 'expires_at' => now()->addWeek(), 'committed_at' => now()]);
        $invoice = SalesInvoice::query()->create(['uuid' => Str::uuid(), 'batch_id' => $batch->id, 'customer_id' => $customer->id, 'employee_id' => $employee->id, 'source_system' => 'primary', 'external_id' => 'I1', 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'currency' => 'USD', 'net_amount_minor' => 10000]);
        SalesReceipt::query()->create(['uuid' => Str::uuid(), 'batch_id' => $batch->id, 'invoice_id' => $invoice->id, 'source_system' => 'primary', 'external_id' => 'R1', 'receipt_date' => '2026-09-15', 'currency' => 'USD', 'amount_minor' => 7000]);
        SalesTarget::query()->create(['uuid' => Str::uuid(), 'batch_id' => $batch->id, 'employee_id' => $employee->id, 'source_system' => 'primary', 'external_id' => 'T1', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'currency' => 'USD', 'amount_minor' => 10000]);
        CompensationRecord::query()->create(['employee_id' => $employee->id, 'annual_base_encrypted' => '1200.00', 'currency_code' => 'USD', 'effective_from' => '2026-01-01', 'effective_to' => null, 'approved_by' => $u->id]);

        return [$t, $u, $employee, $batch, $invoice];
    }

    private function url(): string
    {
        return '/api/advisor/sales/metrics?from=2026-09-01&to=2026-09-30&as_of=2026-10-05&currency=USD';
    }

    private function confirmation(array $quality, array $extra = [])
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/advisor/sales/coverage', array_replace_recursive([
            'from' => '2026-09-01', 'to' => '2026-09-30', 'as_of' => '2026-10-05', 'currency' => 'USD',
            'fingerprint' => $quality['fingerprint'], 'acknowledge' => true, 'source_note' => 'Reviewed independent source export for this entire invoice cohort.',
            'expected' => array_intersect_key($quality['reconciliation']['USD'], array_flip(['invoice_count', 'receipt_count', 'return_count', 'sales_minor', 'returns_minor', 'collected_minor'])),
        ], $extra));
    }

    public function test_reconciliation_distinguishes_absence_from_zero_and_is_separate_by_currency(): void
    {
        [$tenant,$user,,,$invoice] = $this->fixture();
        SalesReturn::query()->create(['uuid' => Str::uuid(), 'batch_id' => $invoice->batch_id, 'invoice_id' => $invoice->id, 'source_system' => 'primary', 'external_id' => 'ret', 'return_date' => '2026-09-20', 'currency' => 'USD', 'amount_minor' => 5000]);
        $q = $this->getJson($this->url())->assertOk()->json('data.quality');
        $this->assertSame('2000', $q['reconciliation']['USD']['credit_minor']);
        $this->assertSame('0', $q['reconciliation']['USD']['difference_minor']);
        $this->assertSame(1, $q['reconciliation']['USD']['return_count']);
        $this->assertFalse($q['coverage_confirmed']);
        $this->assertCount(1, $q['sources']);
        $this->assertSame('2026-09-15', $q['missing']['last_receipt_date']);
        $empty = $this->getJson(str_replace('currency=USD', 'currency=IQD', $this->url()))->assertOk()->json('data.quality');
        $this->assertSame('0', $empty['reconciliation']['IQD']['sales_minor']);
        $this->assertNull($empty['missing']['last_invoice_date']);
        $this->assertFalse($empty['coverage_confirmed']);
    }

    public function test_confirmation_is_encrypted_and_becomes_stale_on_changed_transactions(): void
    {
        [$tenant,$user,,,$invoice] = $this->fixture();
        $q = $this->getJson($this->url())->assertOk()->json('data.quality');
        $response = $this->confirmation($q)->assertCreated();
        $this->assertArrayNotHasKey('review_encrypted', $response->json('data'));
        $this->assertStringNotContainsString('independent source', DB::table('advisor_coverage_confirmations')->value('review_encrypted'));
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.quality.coverage_confirmed', true);
        $this->sign($tenant, $user);
        SalesReceipt::query()->where('invoice_id', $invoice->id)->update(['external_id' => 'replacement']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.quality.coverage.status', 'changed')->assertJsonPath('data.quality.coverage_confirmed', false);
        $this->confirmation($q)->assertStatus(409);
        $fresh = $this->getJson($this->url())->json('data.quality');
        $this->confirmation($fresh)->assertCreated();
        $this->getJson($this->url())->assertJsonPath('data.quality.coverage.status', 'confirmed');
    }

    public function test_mismatched_source_and_missing_acknowledgment_never_confirm_coverage(): void
    {
        $this->fixture();
        $q = $this->getJson($this->url())->json('data.quality');
        $this->confirmation($q, ['expected' => ['sales_minor' => '9999']])->assertStatus(422);
        $this->confirmation($q, ['acknowledge' => false])->assertStatus(422);
        $this->confirmation($q, ['currency' => null])->assertStatus(422);
        $this->assertSame(0, CoverageConfirmation::query()->count());
    }

    public function test_permission_tenant_and_idempotency_boundaries(): void
    {
        [$tenant,$user,$employee] = $this->fixture();
        $q = $this->getJson($this->url())->json('data.quality');
        $this->confirmation($q, ['employee_uuid' => $employee->uuid])->assertStatus(422);
        [$other,$otherUser] = $this->actor();
        $this->confirmation($q)->assertStatus(409);
        $this->getJson($this->url())->assertJsonPath('data.quality.reconciliation.USD.invoice_count', 0);
        $this->sign($tenant, $user);
        $key = (string) Str::uuid();
        $payload = ['from' => '2026-09-01', 'to' => '2026-09-30', 'as_of' => '2026-10-05', 'currency' => 'USD', 'fingerprint' => $q['fingerprint'], 'acknowledge' => true, 'source_note' => 'Reviewed independent source export.', 'expected' => array_intersect_key($q['reconciliation']['USD'], array_flip(['invoice_count', 'receipt_count', 'return_count', 'sales_minor', 'returns_minor', 'collected_minor']))];
        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/advisor/sales/coverage', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/advisor/sales/coverage', $payload)->assertCreated()->assertJsonPath('data.uuid', $first->json('data.uuid'));
        $this->sign($tenant, $user);
        $this->assertSame(1, CoverageConfirmation::query()->count());
        $this->actor($tenant, ['advisor.sales.read', 'data.sensitive.read']);
        $this->confirmation($q)->assertForbidden();
    }

    public function test_metrics_remain_available_before_additive_schema_upgrade(): void
    {
        $this->fixture();
        Schema::drop('advisor_coverage_confirmations');
        $quality = $this->getJson($this->url())->assertOk()->assertJsonPath('data.quality.coverage.available', false)->json('data.quality');
        $this->confirmation($quality)->assertStatus(503);
    }

    public function test_employee_search_finds_records_beyond_first_page_with_stable_pagination_and_tenant_scope(): void
    {
        [$tenant, $user] = $this->actor(null, ['hr.employee.read']);
        for ($i = 1; $i <= 105; $i++) {
            Employee::query()->create(['employee_number' => 'N'.$i, 'first_name' => 'أحمد', 'last_name' => 'علي', 'employment_status' => 'active']);
        }
        $first = $this->getJson('/api/hr/employees?per_page=100&page=1')->assertOk()->assertJsonPath('meta.total', 105)->json('data');
        $second = $this->getJson('/api/hr/employees?per_page=100&page=2')->assertOk()->json('data');
        $this->assertCount(100, $first);
        $this->assertCount(5, $second);
        $this->assertCount(105, array_unique(array_column([...$first, ...$second], 'uuid')));
        $this->getJson('/api/hr/employees?search=N105')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_number', 'N105');
        $this->getJson('/api/hr/employees?search='.urlencode('أحمد علي').'&per_page=50')->assertOk()->assertJsonPath('meta.total', 105);
        $this->actor(null, ['hr.employee.read']);
        $this->getJson('/api/hr/employees?search=N105')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_existing_invoice_imports_work_before_business_catalog_upgrade(): void
    {
        $migration = require database_path('migrations/2026_10_07_000001_create_advisor_quality_foundation.php');
        $migration->down();
        [$tenant, $user, , $batch] = $this->fixture();
        $this->assertFalse(BusinessDataImportService::available());
        app(CsvImportService::class)->write([['values' => ['external_id' => 'I2', 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'customer_number' => 'C1', 'employee_number' => 'E1', 'currency' => 'USD', 'net_amount' => '10.00']]], 'invoices', 'primary', $batch->id);
        $this->assertSame(2, SalesInvoice::query()->count());
        $this->getJson($this->url())->assertOk();
        $this->getJson('/api/advisor/data/reconciliations')->assertStatus(503);
        $this->getJson('/api/advisor/imports/schemas')->assertOk();
    }
}
