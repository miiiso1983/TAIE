<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\AdvisorReport;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Services\ReportExplanationService;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesMarketContextTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $rights = ['advisor.sales.read', 'data.sensitive.read', 'advisor.market.manage', 'advisor.report.read', 'advisor.report.generate']): array
    {
        $tenant = Tenant::query()->create(['name' => 'Market fixture', 'slug' => Str::random(12), 'timezone' => 'Asia/Baghdad']);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Reviewer', 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $rights)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        $this->actingAs($user)->withHeaders(['X-Tenant' => $tenant->uuid]);
        app(TenantContext::class)->setTenant($tenant);

        return [$tenant, $user];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('m', 48), 'teami.billing_enforce' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'UTC'));
    }

    private function source(string $date = '2026-09-01'): array
    {
        return ['kind' => 'fx', 'period_from' => $date, 'period_to' => $date, 'rate_basis' => 'official', 'iqd_per_usd' => '2000', 'title' => 'Synthetic rate; not live market data', 'source_url' => 'https://cbi.iq/page/144', 'publisher' => 'Fixture source', 'source_published_on' => $date, 'review_note' => 'Checked synthetic source and date explicitly', 'acknowledge' => true];
    }

    private function addContext(array $data)
    {
        return $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/market-context', $data);
    }

    private function invoices(User $user): void
    {
        $employee = Employee::query()->create(['employee_number' => 'E1', 'first_name' => 'Fixture', 'last_name' => 'Rep']);
        $customer = Customer::query()->create(['customer_number' => 'C1', 'name' => 'Fixture Customer']);
        $batch = ImportBatch::query()->create(['uuid' => Str::uuid(), 'kind' => 'invoices', 'source_system' => 'primary', 'content_hash' => hash('sha256', 'fixture'), 'status' => 'imported', 'row_count' => 2, 'created_by' => $user->id, 'expires_at' => now()->addWeek()]);
        foreach (['2026-08-31' => 100000000, '2026-09-01' => 200000000] as $date => $amount) {
            SalesInvoice::query()->create(['uuid' => Str::uuid(), 'batch_id' => $batch->id, 'customer_id' => $customer->id, 'employee_id' => $employee->id, 'source_system' => 'primary', 'external_id' => $date, 'invoice_date' => $date, 'due_date' => '2026-10-01', 'currency' => 'IQD', 'net_amount_minor' => $amount]);
        }
    }

    private function query(): string
    {
        return '?from=2026-09-01&to=2026-09-01&as_of=2026-09-01&fx_reference_date=2026-08-31';
    }

    public function test_both_rate_paths_separate_currency_conversion_from_constant_rate_growth(): void
    {
        [, $user] = $this->actor();
        $this->invoices($user);
        $this->addContext([...$this->source('2026-08-31'), 'iqd_per_usd' => '1000'])->assertCreated();
        $this->addContext($this->source())->assertCreated();
        $this->addContext([...$this->source('2026-08-31'), 'rate_basis' => 'market', 'iqd_per_usd' => '1250', 'source_url' => 'https://example.test/market-source'])->assertCreated();
        $this->addContext([...$this->source(), 'rate_basis' => 'market', 'iqd_per_usd' => '2500', 'source_url' => 'https://example.test/market-source'])->assertCreated();
        $this->getJson('/api/advisor/sales/economic-comparison'.$this->query())->assertOk()->assertJsonPath('data.analyses.official.current.fx.observed_usd', '100.000000')->assertJsonPath('data.analyses.official.current.fx.constant_usd', '200.000000')->assertJsonPath('data.analyses.official.comparison.observed_usd_growth_percent', '0.00')->assertJsonPath('data.analyses.official.comparison.constant_usd_growth_percent', '100.00')->assertJsonPath('data.analyses.market.current.fx.observed_usd', '80.000000')->assertJsonPath('data.analyses.market.current.fx.constant_usd', '160.000000')->assertJsonPath('data.coverage_confirmed.current', false);
        $this->getJson('/api/advisor/sales/metrics'.$this->query())->assertOk()->assertJsonPath('data.rows.0.net_sales_minor', '200000000');
        $this->assertDatabaseCount('advisor_market_context', 4);
    }

    public function test_missing_or_revoked_prices_never_fall_back_to_other_basis_or_current_date(): void
    {
        [, $user] = $this->actor();
        $this->invoices($user);
        $rate = $this->addContext($this->source())->assertCreated()->json('data.uuid');
        $this->getJson('/api/advisor/sales/economic-comparison'.$this->query())->assertOk()->assertJsonPath('data.analyses.official.current.fx.constant_usd', null)->assertJsonPath('data.analyses.market.current.fx.observed_usd', null)->assertJsonPath('data.analyses.official.comparison.observed_usd_growth_percent', null);
        $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/market-context/'.$rate.'/revoke', ['note' => 'Synthetic source correction required', 'acknowledge' => true])->assertOk();
        $this->getJson('/api/advisor/sales/economic-comparison'.$this->query())->assertOk()->assertJsonPath('data.analyses.official.current.fx.observed_usd', null)->assertJsonPath('data.analyses.official.current.fx.missing_invoice_dates.0', '2026-09-01');
    }

    public function test_event_scope_is_descriptive_and_report_keeps_original_context_after_revocation(): void
    {
        [, $user] = $this->actor();
        $this->invoices($user);
        $d = $this->source();
        unset($d['rate_basis'], $d['iqd_per_usd']);
        $event = $this->addContext([...$d, 'kind' => 'political', 'category' => 'border_transport', 'title' => 'Synthetic border disruption', 'potential_channel' => 'Potential delayed deliveries; causality not established'])->assertCreated()->json('data.uuid');
        $this->getJson('/api/advisor/sales/economic-comparison'.$this->query())->assertOk()->assertJsonCount(1, 'data.analyses.official.current.events')->assertJsonCount(0, 'data.analyses.official.previous.events');
        $report = $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/reports', ['kind' => 'sales', 'from' => '2026-09-01', 'to' => '2026-09-01', 'as_of' => '2026-09-01', 'fx_reference_date' => '2026-08-31'])->assertOk()->json('data.uuid');
        $this->assertStringNotContainsString('Synthetic border', DB::table('advisor_market_context')->value('payload_encrypted'));
        $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/market-context/'.$event.'/revoke', ['note' => 'Source event was corrected after review', 'acknowledge' => true])->assertOk();
        $this->getJson('/api/advisor/reports/'.$report)->assertOk()->assertJsonCount(1, 'data.snapshot.market_context.official.current.events')->assertJsonPath('data.snapshot.totals.IQD.net_sales_minor', '200000000');
        $this->getJson('/api/advisor/sales/economic-comparison'.$this->query())->assertOk()->assertJsonCount(0, 'data.analyses.official.current.events');
    }

    public function test_sources_validation_duplicates_rights_and_tenant_isolation(): void
    {
        $this->actor();
        $first = $this->addContext($this->source())->assertCreated()->json('data.uuid');
        $this->addContext($this->source())->assertStatus(409);
        $this->addContext([...$this->source('2026-09-02'), 'source_url' => 'https://unrelated.test/rate'])->assertUnprocessable();
        $this->addContext([...$this->source('2026-09-02'), 'iqd_per_usd' => '0'])->assertUnprocessable();
        $this->addContext([...$this->source('2026-09-02'), 'acknowledge' => false])->assertUnprocessable();
        $this->addContext([...$this->source('2026-09-02'), 'period_to' => '2026-09-03'])->assertUnprocessable();
        $this->actor();
        $this->getJson('/api/advisor/market-context')->assertOk()->assertJsonPath('data.total', 0);
        $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/market-context/'.$first.'/revoke', ['note' => 'Reviewed revocation in wrong tenant', 'acknowledge' => true])->assertNotFound();
        $this->actor(['advisor.sales.read', 'data.sensitive.read']);
        $this->addContext($this->source())->assertForbidden();
        $this->assertDatabaseCount('advisor_market_context', 1);
    }

    public function test_usd_only_needs_no_iqd_rate_and_ai_facts_exclude_private_review_notes(): void
    {
        [$tenant, $user] = $this->actor();
        $this->invoices($user);
        SalesInvoice::query()->where('invoice_date', '>=', '2026-08-31')->update(['currency' => 'USD', 'net_amount_minor' => 10000]);
        $this->getJson('/api/advisor/sales/economic-comparison'.$this->query())->assertOk()->assertJsonPath('data.analyses.market.current.fx.observed_usd', '100.000000')->assertJsonPath('data.analyses.market.comparison.constant_usd_growth_percent', '0.00');
        $d = $this->source();
        unset($d['rate_basis'], $d['iqd_per_usd']);
        $this->addContext([...$d, 'kind' => 'political', 'category' => 'security', 'potential_channel' => 'Potential disruption of delivery operations', 'review_note' => 'PRIVATE-REVIEW-NOTE-DO-NOT-SEND'])->assertCreated();
        $id = $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/reports', ['kind' => 'sales', 'from' => '2026-09-01', 'to' => '2026-09-01', 'as_of' => '2026-09-01'])->assertOk()->json('data.uuid');
        app(TenantContext::class)->setTenant($tenant);
        $report = AdvisorReport::query()->where('uuid', $id)->firstOrFail();
        $facts = app(ReportExplanationService::class)->facts($report);
        $encoded = json_encode($facts);
        $this->assertStringNotContainsString('PRIVATE-REVIEW-NOTE', $encoded);
        $this->assertStringContainsString('Potential disruption', $encoded);
        $this->assertStringContainsString('constant_usd_growth_percent', $encoded);
    }
}
