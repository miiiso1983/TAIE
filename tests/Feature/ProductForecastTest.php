<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\InvoiceLine;
use App\Modules\Advisor\Models\Product;
use App\Modules\Advisor\Models\SourceAuthority;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductForecastTest extends TestCase
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

    private function actor(array $permissions = ['data.import.manage', 'data.sensitive.read', 'data.sales.import', 'data.catalog.import', 'advisor.sales.read', 'advisor.data.review', 'advisor.forecast.run', 'hr.employee.create', 'sales.customer.create', 'sales.customer.update']): array
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

    private function series(): array
    {
        $this->setupCatalog();
        $this->imported('customer_locations', "customer_number,area_code\nC1,A1\n");
        $invoices = "external_id,invoice_date,due_date,customer_number,employee_number,currency,net_amount\n";
        $lines = "external_id,invoice_external_id,product_code,quantity,net_amount\n";
        for ($i = 0; $i < 12; $i++) {
            $day = CarbonImmutable::parse('2026-07-06')->addWeeks($i)->toDateString();
            $invoices .= "INV{$i},{$day},2026-12-01,C1,E1,USD,10.00\n";
            $qty = $i + 1;
            $lines .= "L{$i},INV{$i},P1,{$qty},10.00\n";
        }
        $this->imported('invoices', $invoices);
        $this->imported('invoice_lines', $lines);

        return ['product_uuid' => Product::query()->first()->uuid, 'area_uuid' => Area::query()->first()->uuid, 'source_system' => 'primary', 'from' => '2026-07-06', 'to' => '2026-09-27', 'coverage_acknowledge' => true, 'coverage_note' => 'Verified complete weekly sales in source report'];
    }

    private function analyze(array $p)
    {
        return $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/product-forecast', $p);
    }

    public function test_weekly_baselines_use_only_prior_weeks_and_compare_errors(): void
    {
        $p = $this->series();
        $a = $this->analyze($p)->assertOk()->assertJsonPath('data.status', 'experimental')->assertJsonPath('data.forecast.model', 'last_week')->assertJsonPath('data.forecast.quantity', 12)->assertJsonPath('data.backtest.mae.last_week', 1)->assertJsonPath('data.forecast.calibrated', false)->json('data');
        $this->assertCount(6, $a['backtest']['origins']);
        $this->assertSame('2026-09-28', $a['forecast']['from']);
        app(TenantContext::class)->setTenant($this->currentTenant);
        InvoiceLine::query()->where('external_id', 'L11')->update(['quantity' => '100']);
        $b = $this->analyze($p)->assertOk()->json('data');
        $this->assertNotSame($a['fingerprint'], $b['fingerprint']);
        $this->assertSame(array_slice($a['backtest']['origins'], 0, 5), array_slice($b['backtest']['origins'], 0, 5));
    }

    public function test_incomplete_lines_prevent_forecast_and_future_returns_do_not_leak(): void
    {
        $p = $this->series();
        $this->imported('returns', "external_id,invoice_external_id,return_date,currency,amount\nR1,INV0,2026-10-01,USD,1.00\n");
        $this->analyze($p)->assertOk()->assertJsonPath('data.status', 'experimental');
        app(TenantContext::class)->setTenant($this->currentTenant);
        InvoiceLine::query()->where('external_id', 'L1')->update(['net_amount_minor' => 999]);
        $this->analyze($p)->assertOk()->assertJsonPath('data.status', 'insufficient_data')->assertJsonPath('data.forecast', null)->assertJsonPath('data.readiness.incomplete_invoice_count', 1);
    }

    public function test_scope_coverage_period_and_permission_are_enforced(): void
    {
        $p = $this->series();
        $this->analyze([...$p, 'coverage_acknowledge' => false])->assertStatus(422);
        $this->analyze([...$p, 'from' => '2026-07-07'])->assertStatus(422);
        $this->actor();
        $this->analyze($p)->assertNotFound();
        $this->actor(['advisor.sales.read', 'data.sensitive.read']);
        $this->analyze($p)->assertForbidden();
    }

    public function test_forecast_archive_preserves_snapshot_and_deduplicates_exact_inputs(): void
    {
        $p = $this->series();
        $first = $this->analyze($p)->assertOk()->json('data');
        $this->analyze($p)->assertOk()->assertJsonPath('data.uuid', $first['uuid']);
        $this->assertDatabaseCount('advisor_product_forecast_runs', 1);
        $this->assertStringNotContainsString('Verified complete', DB::table('advisor_product_forecast_runs')->value('snapshot_encrypted'));
        app(TenantContext::class)->setTenant($this->currentTenant);
        InvoiceLine::query()->where('external_id', 'L11')->update(['quantity' => '100']);
        $this->analyze($p)->assertOk();
        $this->assertDatabaseCount('advisor_product_forecast_runs', 2);
        $this->getJson('/api/advisor/product-forecasts/'.$first['uuid'])->assertOk()->assertJsonPath('data.forecast.quantity', 12);
        $this->actor();
        $this->getJson('/api/advisor/product-forecasts/'.$first['uuid'])->assertNotFound();
        $this->getJson('/api/advisor/product-forecasts')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_forecasts_honor_registered_invoice_authority_and_retain_revision(): void
    {
        $p = $this->series();
        $u = User::query()->first();
        $a = SourceAuthority::query()->create(['uuid' => Str::uuid(), 'kind' => 'invoices', 'source_system' => 'primary', 'version' => 1, 'reviewed_by' => $u->id, 'review_note_encrypted' => 'Verified source of record']);
        $this->analyze($p)->assertOk()->assertJsonPath('data.source_authority.uuid', (string) $a->uuid);
        app(TenantContext::class)->setTenant($this->currentTenant);
        SourceAuthority::query()->create(['uuid' => Str::uuid(), 'kind' => 'invoices', 'source_system' => 'different', 'version' => 2, 'reviewed_by' => $u->id, 'review_note_encrypted' => 'New verified source of record']);
        $this->analyze($p)->assertStatus(422);
    }

    public function test_holdout_changes_cannot_change_model_selection_and_are_reported_separately(): void
    {
        $p = $this->series();
        $a = $this->analyze($p)->assertOk()->assertJsonPath('data.backtest.independent_holdout', true)->assertJsonPath('data.backtest.holdout_weeks', 4)->assertJsonPath('data.backtest.selection_origins', 2)->json('data');
        app(TenantContext::class)->setTenant($this->currentTenant);
        InvoiceLine::query()->whereIn('external_id', ['L8', 'L9', 'L10', 'L11'])->update(['quantity' => '100']);
        $b = $this->analyze($p)->assertOk()->json('data');
        $this->assertSame($a['forecast']['model'], $b['forecast']['model']);
        $this->assertSame($a['backtest']['mae'], $b['backtest']['mae']);
        $this->assertNotSame($a['backtest']['holdout_mae'], $b['backtest']['holdout_mae']);
    }
}
