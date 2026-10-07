<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\CompanyRule;
use App\Modules\Advisor\Models\RuleSimulation;
use App\Modules\Advisor\Services\CompanyRuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CompanyRuleController extends Controller
{
    private function ready(): void
    {
        abort_unless(Schema::hasTable('advisor_company_rules') && Schema::hasTable('advisor_rule_simulations'), 503, 'Company policy simulations require the pending schema upgrade.');
    }

    private function lock(): void
    {
        Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
    }

    private function policy(Request $r, string $uuid): CompanyRule
    {
        $p = CompanyRule::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(CompanyRuleService::allowed($r->user(), $p->kind), 403);

        return $p;
    }

    public function index(Request $r)
    {
        $this->ready();
        $kinds = array_filter(['credit', 'leave', 'deduction'], fn ($k) => CompanyRuleService::allowed($r->user(), $k));

        return response()->json(['data' => CompanyRule::query()->whereIn('kind', $kinds)->latest('id')->paginate(20)]);
    }

    public function store(Request $r, CompanyRuleService $service, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['kind' => ['required', 'in:credit,leave,deduction'], 'name' => ['required', 'string', 'min:3', 'max:120'], 'valid_from' => ['required', 'date_format:Y-m-d'], 'valid_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'], 'rules' => ['required', 'array']]);
        abort_unless(CompanyRuleService::allowed($r->user(), $d['kind']), 403);
        $rules = $service->rules($d['kind'], $d['rules']);

        return DB::transaction(function () use ($r, $d, $rules, $audit) {
            $this->lock();
            $p = CompanyRule::query()->create(['uuid' => Str::uuid(), 'kind' => $d['kind'], 'name' => trim($d['name']), 'version' => (int) CompanyRule::query()->where('kind', $d['kind'])->where('name', trim($d['name']))->max('version') + 1, 'status' => 'draft', 'valid_from' => $d['valid_from'], 'valid_to' => $d['valid_to'], 'rules_encrypted' => $rules, 'created_by' => $r->user()->id]);
            $audit->record('advisor.rule.created', ['module' => 'advisor', 'entity_type' => 'company_rule', 'entity_id' => $p->uuid, 'new_values' => ['kind' => $p->kind, 'version' => $p->version]]);

            return response()->json(['data' => $p], 201);
        });
    }

    public function simulate(Request $r, string $uuid, CompanyRuleService $service, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['input' => ['required', 'array'], 'evidence_reference' => ['required', 'string', 'min:3', 'max:255'], 'on_date' => ['required', 'date_format:Y-m-d']]);

        return DB::transaction(function () use ($r, $uuid, $d, $service, $audit) {
            $this->lock();
            $p = $this->policy($r, $uuid);
            abort_if($p->status === 'disabled', 409, 'هذه السياسة متوقفة.');
            abort_unless($p->valid_from->toDateString() <= $d['on_date'] && $p->valid_to->toDateString() >= $d['on_date'], 422, 'تاريخ المحاكاة خارج سريان السياسة.');
            $s = $service->evaluate($p->kind, $p->rules_encrypted, $d['input']);
            $s['policy'] = ['uuid' => $p->uuid, 'version' => $p->version, 'status_at_simulation' => $p->status];
            $s['evidence_reference'] = $d['evidence_reference'];
            $s['on_date'] = $d['on_date'];
            $hash = hash('sha256', json_encode([$s, $r->user()->id], JSON_THROW_ON_ERROR));
            $sim = RuleSimulation::query()->firstOrCreate(['policy_id' => $p->id, 'fingerprint' => $hash], ['uuid' => Str::uuid(), 'created_by' => $r->user()->id, 'passed' => $s['passed'], 'snapshot_encrypted' => $s]);
            $audit->record('advisor.rule.simulated', ['module' => 'advisor', 'entity_type' => 'rule_simulation', 'entity_id' => $sim->uuid]);

            return response()->json(['data' => ['uuid' => $sim->uuid, 'snapshot' => $sim->snapshot_encrypted]]);
        });
    }

    public function simulations(Request $r, string $uuid)
    {
        $this->ready();
        $p = $this->policy($r, $uuid);

        return response()->json(['data' => RuleSimulation::query()->where('policy_id', $p->id)->latest('id')->paginate(20)]);
    }

    public function status(Request $r, string $uuid, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['status' => ['required', 'in:active,disabled'], 'review_note' => ['required', 'string', 'min:12', 'max:1000'], 'acknowledge' => ['required', 'accepted']]);

        return DB::transaction(function () use ($r, $uuid, $d, $audit) {
            $this->lock();
            $p = $this->policy($r, $uuid);
            abort_if((int) $p->created_by === (int) $r->user()->id, 403, 'التفعيل أو الإيقاف يحتاج مراجعاً مستقلاً عن منشئ السياسة.');
            if ($d['status'] === 'active') {
                abort_unless($p->status === 'draft', 409, 'يمكن تفعيل المسودة فقط؛ أنشئ نسخة جديدة لتعديل سياسة متوقفة.');
                $tests = RuleSimulation::query()->where('policy_id', $p->id);
                abort_unless((clone $tests)->where('passed', true)->exists() && (clone $tests)->where('passed', false)->exists(), 422, 'اختبر حالة مطابقة وحالة مخالفة قبل التفعيل.');
                abort_if(CompanyRule::query()->where('kind', $p->kind)->where('name', $p->name)->where('status', 'active')->whereDate('valid_from', '<=', $p->valid_to)->whereDate('valid_to', '>=', $p->valid_from)->exists(), 409, 'توجد نسخة نشطة متداخلة لهذه السياسة.');
            } else {
                abort_unless($p->status === 'active', 409, 'يمكن إيقاف النسخة النشطة فقط.');
            }
            $history = $p->status_history_encrypted ?? [];
            $history[] = ['status' => $d['status'], 'actor_id' => $r->user()->id, 'review_note' => $d['review_note'], 'at' => now()->toISOString()];
            $p->update(['status_history_encrypted' => $history, 'status' => $d['status'], 'approved_by' => $r->user()->id, 'approval_note_encrypted' => $d['review_note']]);
            $audit->record('advisor.rule.status_reviewed', ['module' => 'advisor', 'entity_type' => 'company_rule', 'entity_id' => $p->uuid, 'new_values' => ['status' => $p->status]]);

            return response()->json(['data' => $p]);
        });
    }
}
