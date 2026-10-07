<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\BonusPolicy;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Models\SalesReturn;
use App\Modules\Advisor\Models\SalesTarget;
use App\Modules\HR\Models\CompensationRecord;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvisorBonusTest extends TestCase
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

    private function policy(string $mode = 'enforced', array $extra = []): string
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/advisor/bonus-policies', [...['name' => 'Company Policy', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'currency' => 'USD', 'mode' => $mode, 'minimum_sales_percent' => '100', 'minimum_collection_percent' => '80', 'maximum_monthly_base_percent' => '10'], ...$extra])->assertCreated()->json('data.uuid');
    }

    private function request(Employee $e, string $policy, string $amount = '15.00'): string
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/advisor/bonus-requests', ['employee_uuid' => $e->uuid, 'policy_uuid' => $policy, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'as_of' => '2026-10-05', 'amount' => $amount, 'reason' => 'Bonus test justification'])->assertCreated()->json('data.uuid');
    }

    private function decide(string $id, string $decision = 'approve', ?array $detail = null, ?string $key = null)
    {
        $detail ??= $this->getJson('/api/advisor/bonus-requests/'.$id)->assertOk()->json('data');

        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/api/advisor/bonus-requests/'.$id.'/decision', ['decision' => $decision, 'reason' => 'Manager reviewed source and exceptions', 'reviewed_at' => $detail['evaluated_at'], 'review_token' => $detail['review_token']]);
    }

    public function test_review_explains_thresholds_and_cap_with_encrypted_evidence_and_no_payment(): void
    {
        [,,$employee] = $this->fixture();
        $id = $this->request($employee, $this->policy());
        $snapshot = $this->getJson('/api/advisor/bonus-requests/'.$id)->assertOk()->assertJsonPath('data.result', 'objection')->json('data.snapshot');
        $reasons = collect($snapshot['reasons'])->keyBy('code');
        $this->assertSame('met', $reasons['sales_threshold']['level']);
        $this->assertSame('objection', $reasons['collection_threshold']['level']);
        $this->assertSame('objection', $reasons['bonus_cap']['level']);
        $this->assertSame('1000', $snapshot['cap_minor']);
        $this->assertSame('7000', $snapshot['metrics']['collected_minor']);
        $this->assertCount(1, $snapshot['evidence']);
        $this->assertCount(1, $snapshot['transactions']);
        $raw = DB::table('advisor_bonus_requests')->first();
        $this->assertStringNotContainsString('Bonus test', $raw->reason_encrypted);
        $this->assertStringNotContainsString('annual_base', $raw->snapshot_encrypted);
        $this->assertDatabaseCount('hr_payroll_runs', 0);
    }

    public function test_separate_approver_enforced_override_and_replay_safe_final_decision(): void
    {
        [$t,$u,$employee] = $this->fixture();
        $id = $this->request($employee, $this->policy());
        $this->decide($id)->assertForbidden();
        $this->actor($t, array_diff(self::PERMISSIONS, ['advisor.bonus.override']));
        $this->decide($id)->assertForbidden();
        $this->actor($t);
        $detail = $this->getJson('/api/advisor/bonus-requests/'.$id)->json('data');
        $this->decide($id, 'approve', $detail, 'stable-decision')->assertOk()->assertJsonPath('data.overridden', true)->assertJsonPath('data.status', 'approved');
        $this->decide($id, 'approve', $detail, 'stable-decision')->assertOk();
        $this->decide($id, 'reject', $detail)->assertConflict();
        $this->assertDatabaseCount('advisor_bonus_requests', 1);
        $this->assertDatabaseCount('hr_payroll_runs', 0);
        $this->assertDatabaseHas('advisor_bonus_requests', ['uuid' => $id, 'status' => 'approved']);
    }

    public function test_changed_financial_data_requires_reevaluation_and_rejects_old_review_token(): void
    {
        [$t,$u,$employee,$batch,$invoice] = $this->fixture();
        $id = $this->request($employee, $this->policy());
        [, $reviewer] = $this->actor($t);
        $old = $this->getJson('/api/advisor/bonus-requests/'.$id)->json('data');
        $this->sign($t, $reviewer);
        SalesReturn::query()->create(['uuid' => Str::uuid(), 'batch_id' => $batch->id, 'invoice_id' => $invoice->id, 'source_system' => 'primary', 'external_id' => 'RETURN1', 'return_date' => '2026-10-02', 'currency' => 'USD', 'amount_minor' => 1000]);
        $this->decide($id, 'approve', $old)->assertConflict();
        $this->withHeader('Idempotency-Key', 'reevaluate')->postJson('/api/advisor/bonus-requests/'.$id.'/evaluate')->assertOk();
        $this->decide($id, 'approve', $old)->assertConflict();
        $this->decide($id)->assertOk();
    }

    public function test_advisory_still_requires_independent_human_reason_and_missing_data_is_not_a_pass(): void
    {
        [$t,$u,$employee] = $this->fixture();
        SalesTarget::query()->delete();
        CompensationRecord::query()->delete();
        $id = $this->request($employee, $this->policy('advisory', ['minimum_collection_percent' => '0']), '1.00');
        $r = $this->getJson('/api/advisor/bonus-requests/'.$id)->assertJsonPath('data.result', 'needs_review')->json('data');
        $codes = array_column($r['snapshot']['reasons'], 'code');
        $this->assertContains('target_missing', $codes);
        $this->assertContains('compensation_missing', $codes);
        $this->actor($t, array_diff(self::PERMISSIONS, ['advisor.bonus.override']));
        $this->withHeader('Idempotency-Key', 'short-reason')->postJson('/api/advisor/bonus-requests/'.$id.'/decision', ['decision' => 'approve', 'reason' => 'ok', 'reviewed_at' => $r['evaluated_at'], 'review_token' => $r['review_token']])->assertUnprocessable();
        $this->decide($id)->assertOk()->assertJsonPath('data.overridden', true);
    }

    public function test_policy_versions_are_fixed_and_period_permissions_and_tenant_are_enforced(): void
    {
        [$t,$u,$employee] = $this->fixture();
        $p = $this->policy();
        $this->policy();
        $this->getJson('/api/advisor/bonus-policies')->assertOk()->assertJsonPath('data.data.0.version', 2)->assertJsonPath('data.data.1.version', 1);
        $id = $this->request($employee, $p);
        $this->actor();
        $this->getJson('/api/advisor/bonus-requests/'.$id)->assertNotFound();
        $this->getJson('/api/advisor/bonus-requests')->assertJsonCount(0, 'data.data');
        $this->withHeader('Idempotency-Key', 'foreign')->postJson('/api/advisor/bonus-requests', ['employee_uuid' => $employee->uuid, 'policy_uuid' => $p, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'as_of' => '2026-10-05', 'amount' => '1', 'reason' => 'Test valid reason'])->assertNotFound();
        $this->actor(null, ['advisor.bonus.read', 'data.sensitive.read']);
        $this->getJson('/api/advisor/bonus-requests')->assertForbidden();
        $this->sign($t, $u);
        config(['teami.billing_enforce' => true]);
        $this->withHeader('Idempotency-Key', 'expired')->postJson('/api/advisor/bonus-policies', [])->assertForbidden();
    }

    public function test_duplicate_request_is_blocked_and_invalid_policy_period_and_percent_are_rejected(): void
    {
        [$t,$u,$employee] = $this->fixture();
        $policy = $this->policy();
        $payload = ['employee_uuid' => $employee->uuid, 'policy_uuid' => $policy, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'as_of' => '2026-10-05', 'amount' => '5.00', 'reason' => 'Request justification test'];
        $this->withHeader('Idempotency-Key', 'submit-once')->postJson('/api/advisor/bonus-requests', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', 'submit-once')->postJson('/api/advisor/bonus-requests', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', 'different-submit')->postJson('/api/advisor/bonus-requests', $payload)->assertConflict();
        $this->assertDatabaseCount('advisor_bonus_requests', 1);
        $latePolicy = $this->policy('advisory', ['valid_from' => '2026-10-01']);
        $this->withHeader('Idempotency-Key', 'outside-policy')->postJson('/api/advisor/bonus-requests', [...$payload, 'policy_uuid' => $latePolicy])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'percent-limit')->postJson('/api/advisor/bonus-policies', ['name' => 'Bad Rule', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'currency' => 'USD', 'mode' => 'enforced', 'minimum_sales_percent' => '100', 'minimum_collection_percent' => '100.01', 'maximum_monthly_base_percent' => '10'])->assertUnprocessable();
    }

    public function test_iqd_cap_is_rounded_down_in_fils_without_float_or_cross_currency_salary(): void
    {
        [$t,$u,$employee] = $this->fixture();
        SalesInvoice::query()->update(['currency' => 'IQD']);
        SalesReceipt::query()->update(['currency' => 'IQD']);
        SalesTarget::query()->update(['currency' => 'IQD']);
        CompensationRecord::query()->first()->update(['currency_code' => 'IQD', 'annual_base_encrypted' => '12.345']);
        $policy = $this->policy('enforced', ['currency' => 'IQD', 'minimum_collection_percent' => '0']);
        $id = $this->request($employee, $policy, '0.103');
        $r = $this->getJson('/api/advisor/bonus-requests/'.$id)->assertOk()->assertJsonPath('data.snapshot.cap_minor', '102')->json('data.snapshot.reasons');
        $this->assertSame('objection', collect($r)->firstWhere('code', 'bonus_cap')['level']);
        $this->sign($t, $u);
        CompensationRecord::query()->first()->update(['currency_code' => 'USD']);
        $this->withHeader('Idempotency-Key', 'currency-review')->postJson('/api/advisor/bonus-requests/'.$id.'/evaluate')->assertOk();
        $r = $this->getJson('/api/advisor/bonus-requests/'.$id)->assertJsonPath('data.snapshot.cap_minor', null)->json('data.snapshot.reasons');
        $this->assertContains('compensation_missing', array_column($r, 'code'));
    }

    private function import(string $kind, string $csv)
    {
        return $this->post('/api/advisor/imports/preview', ['kind' => $kind, 'source_system' => 'primary', 'file' => UploadedFile::fake()->createWithContent('data.csv', $csv)], ['Accept' => 'application/json']);
    }

    private function commit(string $id)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/advisor/imports/'.$id.'/commit');
    }

    public function test_returns_reduce_net_sales_and_show_credit_separately_without_netting_debts(): void
    {
        [$t,$u,$e] = $this->fixture();
        $b = $this->import('returns', "external_id,invoice_external_id,return_date,currency,amount\nRET1,I1,2026-10-01,USD,40.00\n")->assertOk()->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->getJson('/api/advisor/sales/metrics?from=2026-09-01&to=2026-09-30&as_of=2026-10-05')->assertOk()->assertJsonPath('data.rows.0.net_sales_minor', '6000')->assertJsonPath('data.rows.0.credit_minor', '1000')->assertJsonPath('data.rows.0.balance_minor', '0')->assertJsonPath('data.rows.0.overdue_balance_minor', '0')->assertJsonPath('data.rows.0.achievement_percent', '60.00');
        $this->getJson('/api/advisor/sales/metrics?from=2026-09-01&to=2026-09-30&as_of=2026-09-30')->assertOk()->assertJsonPath('data.rows.0.returns_minor', '0');
        $this->import('returns', "external_id,invoice_external_id,return_date,currency,amount\nRET2,I1,2026-10-01,USD,61.00\n")->assertJsonPath('data.status', 'invalid');
        $this->import('returns', "external_id,invoice_external_id,return_date,currency,amount\nRET3,I1,2026-08-01,USD,1.00\n")->assertJsonPath('data.status', 'invalid');
    }

    public function test_target_and_compensation_import_reject_overlap_and_require_specific_permissions(): void
    {
        [$t,$u,$employee] = $this->fixture();
        $head = "external_id,employee_number,period_from,period_to,currency,amount\n";
        $this->import('targets', $head."T2,E1,2026-09-15,2026-10-31,USD,200.00\n")->assertJsonPath('data.status', 'invalid');
        $this->import('targets', $head."T3,E1,2026-10-01,2026-10-31,USD,200.00\nT4,E1,2026-10-31,2026-11-30,USD,200.00\n")->assertJsonPath('data.status', 'invalid');
        $b = $this->import('targets', $head."T5,E1,2026-10-01,2026-10-31,USD,200.00\n")->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->getJson('/api/advisor/sales/metrics?from=2026-10-01&to=2026-10-05&as_of=2026-10-05')->assertJsonCount(0, 'data.rows');
        $head = "employee_number,annual_base,currency,effective_from,effective_to\n";
        $this->import('compensation', $head."E1,2400.00,USD,2026-02-01,\n")->assertJsonPath('data.status', 'invalid');
        $this->sign($t, $u);
        CompensationRecord::query()->delete();
        $b = $this->import('compensation', $head."E1,2400.00,USD,2026-01-01,2026-12-31\n")->assertJsonPath('data.status', 'validated')->json('data.uuid');
        $this->commit($b)->assertOk();
        $this->assertStringNotContainsString('2400.00', DB::table('hr_compensation_records')->value('annual_base_encrypted'));
        $this->actor($t, array_diff(self::PERMISSIONS, ['hr.compensation.write', 'sales.target.write']));
        $this->import('compensation', $head."E1,2400.00,USD,2027-01-01,\n")->assertForbidden();
        $this->import('targets', '')->assertForbidden();
    }

    public function test_target_without_invoices_is_visible_as_zero_sales_not_missing_rep(): void
    {
        [$t,$u,$employee] = $this->fixture();
        $this->getJson('/api/advisor/sales/metrics?from=2026-09-01&to=2026-09-30&as_of=2026-10-05')->assertJsonPath('data.rows.0.achievement_percent', '100.00');
        $this->sign($t, $u);
        SalesReceipt::query()->delete();
        SalesInvoice::query()->delete();
        $this->getJson('/api/advisor/sales/metrics?from=2026-09-01&to=2026-09-30&as_of=2026-10-05')->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.net_sales_minor', '0')->assertJsonPath('data.rows.0.achievement_percent', '0.00')->assertJsonPath('data.rows.0.collection_percent', null);
    }

    public function test_changed_compensation_prevents_approval_and_rejection_needs_no_override(): void
    {
        [$t,$u,$employee] = $this->fixture();
        $id = $this->request($employee, $this->policy());
        $this->actor($t, array_diff(self::PERMISSIONS, ['advisor.bonus.override']));
        CompensationRecord::query()->first()->update(['annual_base_encrypted' => '2400.00']);
        $this->decide($id, 'reject')->assertConflict();
        $this->withHeader('Idempotency-Key', 'new-review')->postJson('/api/advisor/bonus-requests/'.$id.'/evaluate')->assertOk();
        $this->decide($id, 'reject')->assertOk()->assertJsonPath('data.overridden', false);
    }

    private function stage(string $id, int $stage, string $decision = 'approve', ?array $detail = null)
    {
        $detail ??= $this->getJson('/api/advisor/bonus-requests/'.$id)->assertOk()->json('data');

        return $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/bonus-requests/'.$id.'/decision', ['decision' => $decision, 'reason' => 'Independent reviewer checked all recorded evidence', 'reviewed_at' => $detail['evaluated_at'], 'review_token' => $detail['review_token'], 'approval_stage' => $stage]);
    }

    public function test_multistage_approval_needs_separate_actors_rights_and_current_stage(): void
    {
        [$t,$u,$e] = $this->fixture();
        $id = $this->request($e, $this->policy('enforced', ['approval_steps' => 3]));
        $this->stage($id, 1)->assertForbidden();
        $this->actor($t);
        $this->stage($id, 1)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.approval.completed', 1);
        $this->stage($id, 2)->assertForbidden();
        $this->actor($t, [...self::PERMISSIONS, 'advisor.bonus.approve.stage2']);
        $this->stage($id, 1)->assertConflict();
        $this->stage($id, 2)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->actor($t, [...self::PERMISSIONS, 'advisor.bonus.approve.stage3']);
        $this->stage($id, 3)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approval.completed', 3);
        $this->getJson('/api/advisor/bonus-requests/'.$id)->assertOk()->assertJsonCount(3, 'data.approval_history');
        $this->assertStringNotContainsString('Independent reviewer', DB::table('advisor_bonus_approvals')->value('reason_encrypted'));
    }

    public function test_changed_data_invalidates_all_earlier_stage_approvals(): void
    {
        [$t,$u,$e,$batch,$invoice] = $this->fixture();
        $id = $this->request($e, $this->policy('enforced', ['approval_steps' => 2]));
        $this->actor($t);
        $this->stage($id, 1)->assertOk();
        $old = $this->getJson('/api/advisor/bonus-requests/'.$id)->json('data');
        $this->actor($t, [...self::PERMISSIONS, 'advisor.bonus.approve.stage2']);
        $invoice = SalesInvoice::query()->findOrFail($invoice->id);
        $invoice->update(['net_amount_minor' => 10100]);
        $this->stage($id, 2, 'approve', $old)->assertConflict();
        $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/bonus-requests/'.$id.'/evaluate', [])->assertOk()->assertJsonPath('data.approval.completed', 0);
        $this->getJson('/api/advisor/bonus-requests/'.$id)->assertJsonPath('data.approval_history.0.current', false);
        $this->stage($id, 1)->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_second_stage_can_reject_but_cannot_skip_required_permission(): void
    {
        [$t,$u,$e] = $this->fixture();
        $id = $this->request($e, $this->policy('enforced', ['approval_steps' => 2]));
        $this->actor($t);
        $this->stage($id, 1)->assertOk();
        $this->actor($t);
        $this->stage($id, 2, 'reject')->assertForbidden();
        $this->actor($t, [...self::PERMISSIONS, 'advisor.bonus.approve.stage2']);
        $this->stage($id, 2, 'reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->stage($id, 2)->assertConflict();
        $this->assertDatabaseCount('hr_payroll_runs', 0);
    }

    public function test_policy_search_reaches_older_versions_and_is_tenant_scoped(): void
    {
        [$t,$u] = $this->actor();
        $id = $this->policy('advisory', ['name' => 'Find older policy']);
        $this->sign($t, $u);
        for ($i = 0; $i < 55; $i++) {
            BonusPolicy::query()->create(['uuid' => Str::uuid(), 'name' => 'Other '.$i, 'version' => 1, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'currency' => 'USD', 'mode' => 'advisory', 'rules' => [], 'created_by' => $u->id]);
        }
        $this->getJson('/api/advisor/bonus-policies?search=older&per_page=50')->assertOk()->assertJsonPath('data.data.0.uuid', $id)->assertJsonPath('data.total', 1);
        $this->getJson('/api/advisor/bonus-policies/'.$id)->assertOk();
        $this->actor();
        $this->getJson('/api/advisor/bonus-policies/'.$id)->assertNotFound();
        $this->getJson('/api/advisor/bonus-policies?search=older')->assertOk()->assertJsonPath('data.total', 0);
    }
}
