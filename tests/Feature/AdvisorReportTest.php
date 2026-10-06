<?php

namespace Tests\Feature;

use App\Core\Contracts\LLMProviderInterface;
use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Jobs\GenerateScheduledReport;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\ReportSchedule;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Services\ReportService;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvisorReportTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['advisor.report.read', 'advisor.report.generate', 'advisor.report.manage', 'advisor.report.explain', 'advisor.sales.read', 'data.sensitive.read', 'hr.employee.read', 'hr.leave.read', 'hr.attendance.read', 'ai.ask'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('r', 48), 'teami.billing_enforce' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Baghdad')->utc());
    }

    private function actor(array $permissions = self::PERMISSIONS): array
    {
        $t = Tenant::query()->create(['name' => 'Report Tenant', 'slug' => Str::random(10), 'timezone' => 'Asia/Baghdad', 'status' => 'active']);
        $u = User::factory()->create(['status' => 'active']);
        $t->users()->attach($u, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $t->id, 'name' => 'Reporter', 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_id' => $role->id]);
        $this->sign($t, $u);

        return [$t, $u, $role];
    }

    private function sign(Tenant $t, User $u): void
    {
        $this->actingAs($u)->withHeaders(['X-Tenant' => $t->uuid]);
        app(TenantContext::class)->setTenant($t);
    }

    private function data(User $u): SalesInvoice
    {
        $e = Employee::query()->create(['employee_number' => 'E1', 'first_name' => 'Private', 'last_name' => 'Employee']);
        $c = Customer::query()->create(['customer_number' => 'C1', 'name' => 'Private Customer']);
        $b = ImportBatch::query()->create(['uuid' => Str::uuid(), 'kind' => 'invoices', 'source_system' => 'primary', 'content_hash' => hash('sha256', 'test'), 'status' => 'imported', 'row_count' => 1, 'created_by' => $u->id, 'expires_at' => now()->addWeek(), 'committed_at' => now()]);

        return SalesInvoice::query()->create(['uuid' => Str::uuid(), 'batch_id' => $b->id, 'customer_id' => $c->id, 'employee_id' => $e->id, 'source_system' => 'primary', 'external_id' => 'PRIVATE-INVOICE', 'invoice_date' => '2026-09-15', 'due_date' => '2026-09-30', 'currency' => 'USD', 'net_amount_minor' => 10000]);
    }

    private function generate(string $kind = 'sales', array $extra = []): string
    {
        return $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/reports', [...['kind' => $kind, 'from' => '2026-09-01', 'to' => '2026-09-30', 'as_of' => '2026-09-30'], ...$extra])->assertOk()->json('data.uuid');
    }

    public function test_report_is_encrypted_immutable_and_versions_are_explicit_and_deduplicated(): void
    {
        [$t,$u] = $this->actor();
        $invoice = $this->data($u);
        $id = $this->generate();
        $same = $this->generate();
        $this->assertSame($id, $same);
        $this->getJson('/api/advisor/reports/'.$id)->assertOk()->assertJsonPath('data.snapshot.totals.USD.net_sales_minor', '10000')->assertJsonPath('data.snapshot.previous.from', '2026-08-02');
        $this->assertStringNotContainsString('Private', DB::table('advisor_reports')->value('snapshot_encrypted'));
        $this->sign($t, $u);
        $invoice->update(['net_amount_minor' => 20000]);
        $this->assertSame($id, $this->generate());
        $new = $this->generate('sales', ['new_version' => true]);
        $this->assertNotSame($id, $new);
        $this->getJson('/api/advisor/reports/'.$id)->assertJsonPath('data.snapshot.totals.USD.net_sales_minor', '10000');
        $this->getJson('/api/advisor/reports/'.$new)->assertJsonPath('data.version', 2)->assertJsonPath('data.snapshot.totals.USD.net_sales_minor', '20000');
        $this->assertSame($new, $this->generate('sales', ['new_version' => true]));
        $this->assertDatabaseCount('advisor_reports', 2);
    }

    public function test_hr_report_has_current_status_caveat_and_scoped_permissions(): void
    {
        [$t,$u] = $this->actor();
        $this->data($u);
        $id = $this->generate('hr');
        $r = $this->getJson('/api/advisor/reports/'.$id)->assertOk()->assertJsonPath('data.snapshot.headcount_current.active', 1)->assertJsonPath('data.snapshot.attendance.record_count', 0)->json('data.snapshot');
        $this->assertFalse($r['coverage_confirmed']);
        $this->assertStringContainsString('ليست إعادة بناء تاريخية', $r['notes'][0]);
        $this->actor(['advisor.report.read', 'advisor.report.generate', 'data.sensitive.read', 'advisor.sales.read']);
        $this->withHeader('Idempotency-Key', 'missing-hr')->postJson('/api/advisor/reports', ['kind' => 'hr', 'from' => '2026-09-01', 'to' => '2026-09-30', 'as_of' => '2026-09-30'])->assertForbidden();
        $this->getJson('/api/advisor/reports/'.$id)->assertNotFound();
    }

    public function test_report_scope_permission_is_rechecked_for_existing_tenant_report(): void
    {
        [$t,$u,$role] = $this->actor();
        $id = $this->generate('hr');
        $role->permissions()->detach(Permission::query()->where('name', 'hr.attendance.read')->value('id'));
        $this->getJson('/api/advisor/reports/'.$id)->assertForbidden();
        $this->getJson('/api/advisor/reports')->assertJsonCount(0, 'data.data');
    }

    public function test_scheduler_uses_local_boundaries_runs_once_and_clears_tenant_context(): void
    {
        [$t,$u] = $this->actor();
        $this->data($u);
        $s = $this->withHeader('Idempotency-Key', 'schedule')->postJson('/api/advisor/report-schedules', ['kind' => 'sales', 'frequency' => 'weekly', 'local_hour' => 9])->assertCreated()->json('data');
        $this->assertSame('2026-10-12T06:00:00.000000Z', $s['next_run_at']);
        $this->sign($t, $u);
        ReportSchedule::query()->whereKey($s['id'])->update(['next_run_at' => now()->subMinute()]);
        (new GenerateScheduledReport($t->id, $s['id']))->handle();
        $this->assertFalse(app(TenantContext::class)->hasTenant());
        $this->assertDatabaseCount('advisor_reports', 1);
        $raw = DB::table('advisor_reports')->first();
        $this->assertSame('2026-09-28', substr($raw->period_from, 0, 10));
        $this->assertSame('2026-10-04', substr($raw->period_to, 0, 10));
        $this->assertSame('2026-10-04', substr($raw->as_of, 0, 10));
        (new GenerateScheduledReport($t->id, $s['id']))->handle();
        $this->assertDatabaseCount('advisor_reports', 1);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30', 'as_of' => '2026-09-30'], ReportService::completedPeriod('monthly', CarbonImmutable::parse('2026-10-05', 'Asia/Baghdad')));
    }

    public function test_revoked_schedule_grant_disables_future_runs_and_subscription_is_rechecked(): void
    {
        [$t,$u,$role] = $this->actor();
        $s = $this->withHeader('Idempotency-Key', 'sched')->postJson('/api/advisor/report-schedules', ['kind' => 'sales', 'frequency' => 'daily', 'local_hour' => 9])->json('data');
        $this->sign($t, $u);
        ReportSchedule::query()->whereKey($s['id'])->update(['next_run_at' => now()->subMinute()]);
        $role->permissions()->detach(Permission::query()->where('name', 'advisor.report.generate')->value('id'));
        (new GenerateScheduledReport($t->id, $s['id']))->handle();
        $this->assertDatabaseHas('advisor_report_schedules', ['id' => $s['id'], 'enabled' => false, 'last_error_code' => 'permission_revoked']);
        $this->assertDatabaseCount('advisor_reports', 0);
        $this->sign($t, $u);
        $role->permissions()->sync(Permission::query()->whereIn('name', self::PERMISSIONS)->pluck('id'));
        ReportSchedule::query()->whereKey($s['id'])->update(['enabled' => true]);
        config(['teami.billing_enforce' => true]);
        (new GenerateScheduledReport($t->id, $s['id']))->handle();
        $this->assertDatabaseHas('advisor_report_schedules', ['id' => $s['id'], 'enabled' => false, 'last_error_code' => 'subscription_required']);
    }

    private function fakeModel(string $reply): object
    {
        $fake = new class($reply) implements LLMProviderInterface
        {
            public array $messages = [];

            public int $calls = 0;

            public function __construct(private string $reply) {}

            public function complete(array $messages): array
            {
                $this->calls++;
                $this->messages = $messages;

                return ['content' => $this->reply, 'usage' => ['total_tokens' => 20]];
            }
        };
        $this->app->instance(LLMProviderInterface::class, $fake);

        return $fake;
    }

    public function test_explanation_is_referenced_encrypted_minimized_and_replayed_without_second_model_call(): void
    {
        [$t,$u] = $this->actor();
        $this->data($u);
        $id = $this->generate();
        $fake = $this->fakeModel('{"observations":[{"reference":"F2","text":"طابق اكتمال المصدر قبل اتخاذ قرار مالي."}]}');
        $path = '/api/advisor/reports/'.$id.'/explain';
        $body = ['question' => 'ما الذي يحتاج مراجعة؟'];
        $this->withHeader('Idempotency-Key', 'explain-once')->postJson($path, $body)->assertOk()->assertJsonPath('data.references.0.reference', 'F2');
        $this->withHeader('Idempotency-Key', 'explain-once')->postJson($path, $body)->assertOk();
        $this->assertSame(1, $fake->calls);
        $prompt = $fake->messages[0]['content'];
        $this->assertStringNotContainsString('Private', $prompt);
        $this->assertStringNotContainsString('PRIVATE-INVOICE', $prompt);
        $this->assertStringNotContainsString('annual_base', $prompt);
        $raw = DB::table('advisor_report_explanations')->first();
        $this->assertStringNotContainsString('طابق', $raw->answer_encrypted);
        $this->assertStringNotContainsString('مراجعة', $raw->question_encrypted);
        $this->getJson('/api/advisor/reports/'.$id.'/explanations')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_unknown_references_or_model_generated_numbers_are_rejected_without_saved_output(): void
    {
        $this->actor();
        $id = $this->generate();
        foreach (['{"observations":[{"reference":"invented","text":"تعليق"}]}', '{"observations":[{"reference":"F1","text":"المبيعات 9999"}]}', 'plain invented summary'] as $i => $bad) {
            $this->fakeModel($bad);
            $this->withHeader('Idempotency-Key', 'bad-'.$i)->postJson('/api/advisor/reports/'.$id.'/explain', ['question' => 'فسّر التقرير'])->assertStatus(502);
        }$this->assertDatabaseCount('advisor_report_explanations', 0);
    }

    public function test_model_is_not_called_for_foreign_scope_or_missing_explain_permission(): void
    {
        [$t,$u] = $this->actor();
        $id = $this->generate();
        $fake = $this->fakeModel('{}');
        $this->actor();
        $this->withHeader('Idempotency-Key', 'foreign-ai')->postJson('/api/advisor/reports/'.$id.'/explain', ['question' => 'Explain'])->assertNotFound();
        $this->assertSame(0, $fake->calls);
        $this->actor(['advisor.report.read', 'data.sensitive.read', 'advisor.sales.read', 'ai.ask']);
        $this->withHeader('Idempotency-Key', 'no-ai-grant')->postJson('/api/advisor/reports/'.$id.'/explain', ['question' => 'Explain'])->assertForbidden();
        $this->assertSame(0, $fake->calls);
    }

    public function test_inactive_membership_and_schedule_disable_stop_background_generation(): void
    {
        [$t,$u] = $this->actor();
        $s = $this->withHeader('Idempotency-Key', 'sched-stop')->postJson('/api/advisor/report-schedules', ['kind' => 'sales', 'frequency' => 'daily', 'local_hour' => 9])->json('data');
        $this->withHeader('Idempotency-Key', 'disable')->postJson('/api/advisor/report-schedules/'.$s['uuid'].'/disable')->assertOk();
        $this->sign($t, $u);
        ReportSchedule::query()->whereKey($s['id'])->update(['next_run_at' => now()->subMinute()]);
        (new GenerateScheduledReport($t->id, $s['id']))->handle();
        $this->assertDatabaseCount('advisor_reports', 0);
        $this->sign($t, $u);
        ReportSchedule::query()->whereKey($s['id'])->update(['enabled' => true]);
        DB::table('tenant_users')->where('tenant_id', $t->id)->where('user_id', $u->id)->update(['status' => 'inactive']);
        (new GenerateScheduledReport($t->id, $s['id']))->handle();
        $this->assertDatabaseHas('advisor_report_schedules', ['id' => $s['id'], 'enabled' => false, 'last_error_code' => 'permission_revoked']);
    }

    public function test_provider_failures_are_safe_and_unconfigured_ai_saves_no_explanation(): void
    {
        $this->actor();
        $id = $this->generate();
        config(['teami_ai.endpoint' => 'https://provider.example/v1', 'teami_ai.api_key' => null]);
        $this->withHeader('Idempotency-Key', 'unconfigured')->postJson('/api/advisor/reports/'.$id.'/explain', ['question' => 'فسّر التقرير'])->assertStatus(503);
        $this->assertDatabaseCount('advisor_report_explanations', 0);
        config(['teami_ai.api_key' => 'fixture-token']);
        Http::preventStrayRequests();
        Http::fake(['provider.example/*' => Http::response(['error' => 'provider internal details'], 401)]);
        $this->withHeader('Idempotency-Key', 'rejected-provider')->postJson('/api/advisor/reports/'.$id.'/explain', ['question' => 'فسّر التقرير'])->assertStatus(502)->assertDontSee('provider internal details');
        Http::fake(['provider.example/*' => Http::response(['choices' => []], 200)]);
        $this->withHeader('Idempotency-Key', 'empty-provider')->postJson('/api/advisor/reports/'.$id.'/explain', ['question' => 'فسّر التقرير'])->assertStatus(502);
        $this->assertDatabaseCount('advisor_report_explanations', 0);
    }

    public function test_failed_job_disables_only_its_own_schedule_with_safe_error_code(): void
    {
        [$t,$u] = $this->actor();
        $s = $this->withHeader('Idempotency-Key', 'failure-schedule')->postJson('/api/advisor/report-schedules', ['kind' => 'sales', 'frequency' => 'daily', 'local_hour' => 9])->json('data');
        [$foreign,$other] = $this->actor();
        (new GenerateScheduledReport($foreign->id, $s['id']))->failed(new \RuntimeException('sensitive failure text'));
        $this->assertDatabaseHas('advisor_report_schedules', ['id' => $s['id'], 'enabled' => true]);
        (new GenerateScheduledReport($t->id, $s['id']))->failed(new \RuntimeException('sensitive failure text'));
        $this->assertDatabaseHas('advisor_report_schedules', ['id' => $s['id'], 'enabled' => false, 'last_error_code' => 'generation_failed']);
    }
}
