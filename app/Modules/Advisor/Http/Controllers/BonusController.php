<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Authorization\PolicyEvaluator;
use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\BonusPolicy;
use App\Modules\Advisor\Models\BonusRequest;
use App\Modules\Advisor\Services\BonusReviewService;
use App\Modules\Advisor\Services\CsvImportService;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BonusController extends Controller
{
    private function lockTenant(): void
    {
        Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
    }

    public function policies()
    {
        return response()->json(['data' => BonusPolicy::query()->latest('id')->paginate(20)]);
    }

    public function createPolicy(Request $r, AuditTrailService $audit)
    {
        $percent = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,3})(\.[0-9]{1,2})?$/D'];
        $d = $r->validate(['name' => ['required', 'string', 'max:120'], 'valid_from' => ['required', 'date_format:Y-m-d'], 'valid_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'], 'currency' => ['required', 'in:USD,IQD'], 'mode' => ['required', 'in:advisory,enforced'], 'minimum_sales_percent' => $percent, 'minimum_collection_percent' => $percent, 'maximum_monthly_base_percent' => $percent]);
        foreach (['minimum_sales_percent' => '1000', 'minimum_collection_percent' => '100', 'maximum_monthly_base_percent' => '1000'] as $key => $max) {
            abort_if(bccomp($d[$key], $max, 2) > 0 || ($key === 'maximum_monthly_base_percent' && bccomp($d[$key], '0', 2) <= 0), 422, 'نسبة خارج الحدود المسموحة.');
        }
        $policy = DB::transaction(function () use ($r, $d, $audit) {
            $this->lockTenant();
            $rules = array_intersect_key($d, array_flip(['minimum_sales_percent', 'minimum_collection_percent', 'maximum_monthly_base_percent']));
            $p = BonusPolicy::query()->create(['uuid' => (string) Str::uuid(), 'name' => trim($d['name']), 'version' => (int) BonusPolicy::query()->where('name', trim($d['name']))->max('version') + 1, 'valid_from' => $d['valid_from'], 'valid_to' => $d['valid_to'], 'currency' => $d['currency'], 'mode' => $d['mode'], 'rules' => $rules, 'created_by' => $r->user()->id]);
            $audit->record('advisor.policy.created', ['module' => 'advisor', 'entity_type' => 'bonus_policy', 'entity_id' => $p->uuid, 'new_values' => ['version' => $p->version, 'mode' => $p->mode, 'rules' => $rules]]);

            return $p;
        });

        return response()->json(['data' => $policy], 201);
    }

    private function summary(BonusRequest $b): array
    {
        return ['uuid' => $b->uuid, 'employee_uuid' => Employee::withTrashed()->whereKey($b->employee_id)->value('uuid'), 'employee_name' => $b->snapshot_encrypted['employee']['name'], 'period_from' => $b->period_from->toDateString(), 'period_to' => $b->period_to->toDateString(), 'as_of' => $b->as_of->toDateString(), 'currency' => $b->currency, 'amount_minor' => (string) $b->amount_minor, 'status' => $b->status, 'result' => $b->snapshot_encrypted['result'], 'submitted_by' => $b->submitted_by, 'decided_by' => $b->decided_by, 'overridden' => $b->overridden, 'evaluated_at' => $b->evaluated_at->toISOString(), 'decided_at' => $b->decided_at?->toISOString()];
    }

    public function index()
    {
        return response()->json(['data' => BonusRequest::query()->latest('id')->paginate(20)->through(fn ($b) => $this->summary($b))]);
    }

    public function show(string $uuid)
    {
        $b = BonusRequest::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => [...$this->summary($b), 'reason' => $b->reason_encrypted, 'decision_reason' => $b->decision_reason_encrypted, 'review_token' => $b->fingerprint, 'snapshot' => $b->snapshot_encrypted]]);
    }

    public function submit(Request $r, CsvImportService $money, BonusReviewService $review, AuditTrailService $audit, Outbox $outbox)
    {
        $today = now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString();
        $d = $r->validate(['employee_uuid' => ['required', 'uuid'], 'policy_uuid' => ['required', 'uuid'], 'period_from' => ['required', 'date_format:Y-m-d'], 'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'], 'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_to', 'before_or_equal:'.$today], 'amount' => ['required', 'string'], 'reason' => ['required', 'string', 'min:12', 'max:2000']]);
        abort_if(CarbonImmutable::parse($d['period_from'])->diffInDays(CarbonImmutable::parse($d['period_to'])) > 366, 422, 'الفترة القصوى 367 يوماً.');
        $b = DB::transaction(function () use ($r, $d, $money, $review, $audit, $outbox) {
            $this->lockTenant();
            $employee = Employee::query()->where('uuid', $d['employee_uuid'])->lockForUpdate()->firstOrFail();
            $policy = BonusPolicy::query()->where('uuid', $d['policy_uuid'])->firstOrFail();
            abort_unless($policy->valid_from->toDateString() <= $d['period_from'] && $policy->valid_to->toDateString() >= $d['period_to'], 422, 'السياسة لا تغطي كامل فترة الطلب.');
            $amount = $money->minor($d['amount'], $policy->currency);
            abort_unless($amount !== null, 422, 'مبلغ موجب مطلوب بدقة العملة ودون فواصل آلاف.');
            abort_if(BonusRequest::query()->where('employee_id', $employee->id)->where('currency', $policy->currency)->whereDate('period_from', $d['period_from'])->whereDate('period_to', $d['period_to'])->whereIn('status', ['pending', 'approved'])->exists(), 409, 'يوجد طلب قيد المراجعة أو معتمد لنفس الموظف والعملة والفترة.');
            $evaluation = $review->evaluate($employee, $policy, [...$d, 'amount_minor' => $amount]);
            $b = BonusRequest::query()->create(['uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'policy_id' => $policy->id, 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'as_of' => $d['as_of'], 'currency' => $policy->currency, 'amount_minor' => $amount, 'reason_encrypted' => $d['reason'], 'snapshot_encrypted' => $evaluation['snapshot'], 'fingerprint' => $evaluation['fingerprint'], 'submitted_by' => $r->user()->id, 'status' => 'pending', 'evaluated_at' => now()]);
            $audit->record('advisor.bonus.submitted', ['module' => 'advisor', 'entity_type' => 'bonus_request', 'entity_id' => $b->uuid, 'new_values' => ['policy_uuid' => $policy->uuid, 'result' => $evaluation['snapshot']['result']]]);
            $outbox->append('advisor.bonus.submitted', ['request_uuid' => $b->uuid]);

            return $b;
        });

        return response()->json(['data' => $this->summary($b)], 201);
    }

    private function evaluate(BonusRequest $b, BonusReviewService $review): array
    {
        $employee = Employee::withTrashed()->whereKey($b->employee_id)->lockForUpdate()->firstOrFail();
        $policy = BonusPolicy::query()->whereKey($b->policy_id)->firstOrFail();

        return $review->evaluate($employee, $policy, ['period_from' => $b->period_from->toDateString(), 'period_to' => $b->period_to->toDateString(), 'as_of' => $b->as_of->toDateString(), 'amount_minor' => $b->amount_minor]);
    }

    public function refresh(Request $r, string $uuid, BonusReviewService $review, AuditTrailService $audit)
    {
        $b = DB::transaction(function () use ($uuid, $review, $audit) {
            $this->lockTenant();
            $b = BonusRequest::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($b->status === 'pending', 409, 'تم اتخاذ قرار لهذا الطلب.');
            $evaluation = $this->evaluate($b, $review);
            $b->update(['snapshot_encrypted' => $evaluation['snapshot'], 'fingerprint' => $evaluation['fingerprint'], 'evaluated_at' => now()]);
            $audit->record('advisor.bonus.reevaluated', ['module' => 'advisor', 'entity_type' => 'bonus_request', 'entity_id' => $b->uuid, 'new_values' => ['result' => $evaluation['snapshot']['result']]]);

            return $b;
        });

        return response()->json(['data' => $this->summary($b)]);
    }

    public function decide(Request $r, string $uuid, BonusReviewService $review, AuditTrailService $audit, Outbox $outbox)
    {
        $d = $r->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required', 'string', 'min:12', 'max:2000'], 'reviewed_at' => ['required', 'string', 'max:40'], 'review_token' => ['required', 'string', 'size:64']]);
        $b = DB::transaction(function () use ($r, $uuid, $d, $review, $audit, $outbox) {
            $this->lockTenant();
            $b = BonusRequest::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($b->status === 'pending', 409, 'تم اتخاذ قرار لهذا الطلب.');
            abort_if($b->submitted_by === $r->user()->id, 403, 'مقدم الطلب لا يستطيع اعتماد أو رفض طلبه.');
            abort_unless(hash_equals($b->evaluated_at->toISOString(), $d['reviewed_at']) && hash_equals($b->fingerprint, $d['review_token']), 409, 'تغيرت المراجعة؛ افتح تفاصيل الطلب مجدداً.');
            $evaluation = $this->evaluate($b, $review);
            abort_unless(hash_equals($b->fingerprint, $evaluation['fingerprint']), 409, 'تغيرت البيانات منذ المراجعة؛ أعد التقييم واقرأ الأسباب الجديدة قبل القرار.');
            $overridden = $d['decision'] === 'approve' && collect($evaluation['snapshot']['reasons'])->contains(fn ($reason) => in_array($reason['level'], ['objection', 'missing'], true));
            if ($overridden && $evaluation['snapshot']['policy']['mode'] === 'enforced') {
                abort_unless(app(PolicyEvaluator::class)->allows($r->user(), 'advisor.bonus.override'), 403, 'السياسة إلزامية؛ التجاوز يتطلب صلاحية مستقلة وسبباً موثقاً.');
            }
            $b->update(['status' => $d['decision'] === 'approve' ? 'approved' : 'rejected', 'decided_by' => $r->user()->id, 'decision_reason_encrypted' => $d['reason'], 'overridden' => $overridden, 'decided_at' => now()]);
            $audit->record('advisor.bonus.decided', ['module' => 'advisor', 'entity_type' => 'bonus_request', 'entity_id' => $b->uuid, 'new_values' => ['status' => $b->status, 'overridden' => $overridden, 'fingerprint' => $b->fingerprint]]);
            $outbox->append('advisor.bonus.decided', ['request_uuid' => $b->uuid, 'status' => $b->status, 'overridden' => $overridden]);

            return $b;
        });

        return response()->json(['data' => $this->summary($b)]);
    }
}
