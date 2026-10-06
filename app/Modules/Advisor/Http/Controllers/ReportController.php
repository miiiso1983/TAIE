<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\AdvisorReport;
use App\Modules\Advisor\Models\ReportExplanation;
use App\Modules\Advisor\Models\ReportSchedule;
use App\Modules\Advisor\Services\ReportExplanationService;
use App\Modules\Advisor\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    private function summary(AdvisorReport $r): array
    {
        return ['uuid' => $r->uuid, 'kind' => $r->kind, 'period_from' => $r->period_from->toDateString(), 'period_to' => $r->period_to->toDateString(), 'as_of' => $r->as_of->toDateString(), 'version' => $r->version, 'created_at' => $r->created_at->toISOString()];
    }

    public function index(Request $r)
    {
        $kinds = array_filter(['sales', 'hr'], fn ($kind) => ReportService::allowed($r->user(), $kind));

        return response()->json(['data' => AdvisorReport::query()->whereIn('kind', $kinds)->latest('id')->paginate(20)->through(fn ($report) => $this->summary($report))]);
    }

    public function show(Request $r, string $uuid)
    {
        $report = AdvisorReport::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(ReportService::allowed($r->user(), $report->kind), 403);

        return response()->json(['data' => [...$this->summary($report), 'snapshot' => $report->snapshot_encrypted]]);
    }

    public function generate(Request $r, ReportService $service)
    {
        $today = now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString();
        $d = $r->validate(['kind' => ['required', 'in:sales,hr'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:to', 'before_or_equal:'.$today], 'new_version' => ['sometimes', 'boolean']]);
        abort_if(CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) > 366, 422, 'The report period must be at most 367 days.');
        $report = $service->generate($d, $r->user());

        return response()->json(['data' => $this->summary($report)]);
    }

    public function schedules(Request $r)
    {
        return response()->json(['data' => ReportSchedule::query()->latest('id')->get()]);
    }

    public function createSchedule(Request $r, AuditTrailService $audit)
    {
        $d = $r->validate(['kind' => ['required', 'in:sales,hr'], 'frequency' => ['required', 'in:daily,weekly,monthly'], 'local_hour' => ['required', 'integer', 'min:0', 'max:23']]);
        abort_unless(ReportService::allowed($r->user(), $d['kind']), 403);
        $s = DB::transaction(function () use ($d, $r, $audit) {
            $tenant = app(TenantContext::class)->requireTenant();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = ReportSchedule::query()->where('kind', $d['kind'])->where('frequency', $d['frequency'])->first();
            abort_if($existing && $existing->enabled, 409, 'يوجد جدول نشط لنفس النوع والتكرار؛ أوقفه أولاً.');
            $s = $existing ?? new ReportSchedule(['uuid' => Str::uuid(), 'kind' => $d['kind'], 'frequency' => $d['frequency']]);
            $s->fill(['owner_id' => $r->user()->id, 'local_hour' => $d['local_hour'], 'enabled' => true, 'last_error_code' => null, 'next_run_at' => ReportService::nextRun($d['frequency'], (int) $d['local_hour'], CarbonImmutable::now($tenant->timezone ?: 'UTC'))])->save();
            $audit->record('advisor.schedule.enabled', ['module' => 'advisor', 'entity_type' => 'report_schedule', 'entity_id' => $s->uuid, 'new_values' => ['kind' => $s->kind, 'frequency' => $s->frequency, 'local_hour' => $s->local_hour]]);

            return $s;
        });

        return response()->json(['data' => $s], 201);
    }

    public function disableSchedule(Request $r, string $uuid, AuditTrailService $audit)
    {
        return DB::transaction(function () use ($uuid, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $s = ReportSchedule::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $s->update(['enabled' => false]);
            $audit->record('advisor.schedule.disabled', ['module' => 'advisor', 'entity_type' => 'report_schedule', 'entity_id' => $s->uuid]);

            return response()->json(['data' => $s]);
        });
    }

    public function explain(Request $r, string $uuid, ReportExplanationService $service)
    {
        $d = $r->validate(['question' => ['required', 'string', 'max:2000']]);
        $report = AdvisorReport::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(ReportService::allowed($r->user(), $report->kind), 403);

        return response()->json(['data' => $service->explain($report, $r->user(), $d['question'])]);
    }

    public function explanations(Request $r, string $uuid)
    {
        $report = AdvisorReport::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(ReportService::allowed($r->user(), $report->kind), 403);
        $rows = ReportExplanation::query()->where('report_id', $report->id)->where('user_id', $r->user()->id)->latest('id')->paginate(10)->through(fn ($x) => ['uuid' => $x->uuid, 'question' => $x->question_encrypted, 'observations' => json_decode($x->answer_encrypted, true), 'references' => $x->references, 'created_at' => $x->created_at->toISOString()]);

        return response()->json(['data' => $rows]);
    }
}
