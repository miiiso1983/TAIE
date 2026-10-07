<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\AdvisorReport;
use App\Modules\Advisor\Models\Decision;
use App\Modules\Advisor\Models\DecisionEvent;
use App\Modules\Advisor\Models\ProductForecastRun;
use App\Modules\Advisor\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DecisionController extends Controller
{
    private const NEXT = ['proposed' => ['accepted', 'rejected'], 'accepted' => ['in_progress', 'cancelled'], 'in_progress' => ['completed', 'cancelled'], 'completed' => [], 'rejected' => [], 'cancelled' => []];

    private function ready(): void
    {
        abort_unless(Schema::hasTable('advisor_decisions') && Schema::hasTable('advisor_decision_events'), 503, 'مركز القرارات ينتظر ترقية النظام.');
    }

    private function report(Request $r, int $id): AdvisorReport
    {
        $report = AdvisorReport::query()->findOrFail($id);
        abort_unless(ReportService::allowed($r->user(), $report->kind), 403);

        return $report;
    }

    private function canForecast(Request $r): bool
    {
        $tenant = app(TenantContext::class)->getTenantId();

        return $r->user()->hasTenantPermission($tenant, 'advisor.forecast.run') && $r->user()->hasTenantPermission($tenant, 'advisor.sales.read');
    }

    private function evidence(Request $r, Decision $d): void
    {
        if ($d->forecast_run_id) {
            abort_unless($this->canForecast($r), 403);
            ProductForecastRun::query()->findOrFail($d->forecast_run_id);
        } else {
            $this->report($r, $d->report_id);
        }
    }

    private function owner(int $id): void
    {
        abort_unless(DB::table('tenant_users')->where('tenant_id', app(TenantContext::class)->getTenantId())->where('user_id', $id)->where('status', 'active')->exists() && User::query()->whereKey($id)->where('status', 'active')->exists(), 422, 'المسؤول يجب أن يكون عضواً نشطاً في الشركة.');
    }

    private function present(Decision $d): array
    {
        return ['uuid' => $d->uuid, 'status' => $d->status, 'due_date' => $d->due_date->toDateString(), 'version' => $d->version, 'owner_id' => $d->owner_id, 'owner_name' => User::query()->find($d->owner_id)?->name, 'details' => $d->details_encrypted, 'overdue' => ! in_array($d->status, ['completed', 'rejected', 'cancelled']) && $d->due_date->toDateString() < now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString()];
    }

    public function owners(Request $r)
    {
        $d = $r->validate(['search' => ['sometimes', 'string', 'max:100']]);

        return response()->json(['data' => User::query()->where('status', 'active')->whereIn('id', DB::table('tenant_users')->select('user_id')->where('tenant_id', app(TenantContext::class)->getTenantId())->where('status', 'active'))->when($d['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))->orderBy('id')->paginate(50, ['id', 'name'])->through(fn ($u) => ['uuid' => (string) $u->id, 'name' => $u->name])]);
    }

    public function index(Request $r)
    {
        $this->ready();
        $d = $r->validate(['status' => ['sometimes', 'in:proposed,accepted,in_progress,completed,rejected,cancelled']]);
        $kinds = array_values(array_filter(['sales', 'hr'], fn ($k) => ReportService::allowed($r->user(), $k)));

        return response()->json(['data' => Decision::query()->where(function ($q) use ($kinds, $r) {
            $q->whereIn('report_id', AdvisorReport::query()->whereIn('kind', $kinds)->select('id'));
            if (Schema::hasColumn('advisor_decisions', 'forecast_run_id') && $this->canForecast($r)) {
                $q->orWhereNotNull('forecast_run_id');
            }
        })->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->paginate(20)->through(fn ($x) => $this->present($x))]);
    }

    public function show(Request $r, string $uuid)
    {
        $this->ready();
        $d = Decision::query()->where('uuid', $uuid)->firstOrFail();
        $this->evidence($r, $d);

        return response()->json(['data' => [...$this->present($d), 'events' => DecisionEvent::query()->where('decision_id', $d->id)->orderBy('version')->get(['version', 'status', 'actor_id', 'evidence_encrypted', 'created_at'])]]);
    }

    public function store(Request $r, AuditTrailService $audit)
    {
        $this->ready();
        abort_if($r->filled('report_uuid') && $r->filled('forecast_uuid'), 422, 'اختر نوع دليل واحداً فقط.');
        $d = $r->validate(['report_uuid' => ['required_without:forecast_uuid', 'uuid'], 'forecast_uuid' => ['required_without:report_uuid', 'uuid'], 'title' => ['required', 'string', 'min:3', 'max:200'], 'rationale' => ['required', 'string', 'min:12', 'max:2000'], 'owner_id' => ['required', 'integer'], 'due_date' => ['required', 'date_format:Y-m-d'], 'metric' => ['required', 'string', 'min:3', 'max:100'], 'unit' => ['required', 'in:USD_minor,IQD_minor,count,percent,quantity_milli'], 'baseline' => ['required', 'integer', 'between:-1000000000000,1000000000000'], 'target' => ['required', 'integer', 'between:-1000000000000,1000000000000']]);

        return DB::transaction(function () use ($r, $d, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $this->owner($d['owner_id']);
            $report = null;
            $forecast = null;
            if (isset($d['forecast_uuid'])) {
                abort_unless(Schema::hasColumn('advisor_decisions', 'forecast_run_id') && Schema::hasTable('advisor_product_forecast_runs'), 503, 'Forecast decision links require the pending schema upgrade.');
                abort_unless($this->canForecast($r), 403);
                $forecast = ProductForecastRun::query()->where('uuid', $d['forecast_uuid'])->firstOrFail();
                abort_unless($forecast->snapshot_encrypted['status'] === 'experimental', 422, 'لا تربط قرار توقع بتحليل غير كافٍ.');
                abort_unless($d['unit'] === 'quantity_milli', 422, 'قياس التوقع يجب أن يكون بأجزاء ألف من وحدة المنتج.');
            } else {
                $report = AdvisorReport::query()->where('uuid', $d['report_uuid'])->firstOrFail();
                $this->report($r, $report->id);
            }
            $today = now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString();
            abort_if($d['due_date'] < $today, 422, 'موعد الإجراء يجب ألا يسبق اليوم.');
            $details = array_diff_key($d, array_flip(['report_uuid', 'forecast_uuid', 'owner_id', 'due_date']));
            if ($forecast) {
                $snap = $forecast->snapshot_encrypted;
                $details['forecast'] = ['uuid' => $forecast->uuid, 'fingerprint' => $forecast->fingerprint, 'algorithm_version' => $snap['algorithm_version'], 'product' => $snap['product'], 'area' => $snap['area'], 'scope' => $snap['scope'], 'forecast' => $snap['forecast'], 'saved_at' => $forecast->created_at->toISOString()];
            } else {
                $details['report'] = ['uuid' => $report->uuid, 'kind' => $report->kind, 'version' => $report->version, 'period_from' => $report->period_from->toDateString(), 'period_to' => $report->period_to->toDateString(), 'as_of' => $report->as_of->toDateString()];
            }
            $item = Decision::query()->create(['uuid' => Str::uuid(), 'report_id' => $report?->id, 'owner_id' => $d['owner_id'], 'created_by' => $r->user()->id, 'due_date' => $d['due_date'], 'status' => 'proposed', 'version' => 1, 'details_encrypted' => $details, ...($forecast ? ['forecast_run_id' => $forecast->id] : [])]);
            DecisionEvent::query()->create(['decision_id' => $item->id, 'actor_id' => $r->user()->id, 'version' => 1, 'status' => 'proposed', 'evidence_encrypted' => ['note' => $d['rationale']], 'created_at' => now()]);
            $audit->record('advisor.decision.created', ['module' => 'advisor', 'entity_type' => 'decision', 'entity_id' => $item->uuid]);

            return response()->json(['data' => $this->present($item)], 201);
        });
    }

    public function transition(Request $r, string $uuid, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:accepted,rejected,in_progress,completed,cancelled'], 'note' => ['required', 'string', 'min:12', 'max:2000'], 'actual' => ['required_if:status,completed', 'prohibited_unless:status,completed', 'integer', 'between:-1000000000000,1000000000000'], 'evidence_reference' => ['required_if:status,completed', 'prohibited_unless:status,completed', 'string', 'min:3', 'max:255']]);

        return DB::transaction(function () use ($r, $uuid, $d, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $item = Decision::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $this->evidence($r, $item);
            abort_unless($item->version === $d['version'], 409, 'تغير القرار؛ حدّث الصفحة قبل المتابعة.');
            abort_unless(in_array($d['status'], self::NEXT[$item->status]), 422, 'انتقال الحالة غير مسموح.');
            if (in_array($d['status'], ['accepted', 'in_progress'])) {
                $this->owner($item->owner_id);
            }
            $details = $item->details_encrypted;
            if ($d['status'] === 'completed') {
                $details['outcome'] = ['actual' => (int) $d['actual'], 'delta' => (int) $d['actual'] - $details['baseline'], 'target_gap' => (int) $d['actual'] - $details['target'], 'evidence_reference' => $d['evidence_reference'], 'measured_at' => now()->toISOString(), 'notice' => 'نتيجة موثقة بشرياً؛ الفرق لا يثبت أن الإجراء سبب التغيير.'];
            }
            $item->update(['status' => $d['status'], 'version' => $item->version + 1, 'details_encrypted' => $details]);
            DecisionEvent::query()->create(['decision_id' => $item->id, 'actor_id' => $r->user()->id, 'version' => $item->version, 'status' => $item->status, 'evidence_encrypted' => array_diff_key($d, ['version' => true, 'status' => true]), 'created_at' => now()]);
            $audit->record('advisor.decision.transitioned', ['module' => 'advisor', 'entity_type' => 'decision', 'entity_id' => $item->uuid, 'new_values' => ['status' => $item->status, 'version' => $item->version]]);

            return response()->json(['data' => $this->present($item)]);
        });
    }
}
