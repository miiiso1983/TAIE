<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\ForecastSchedule;
use App\Modules\Advisor\Models\Product;
use App\Modules\Advisor\Models\ProductForecastRun;
use App\Modules\Advisor\Services\ForecastArchiveService;
use App\Modules\Advisor\Services\ForecastScheduleService;
use App\Modules\Advisor\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ForecastScheduleController extends Controller
{
    private function ready(): void
    {
        abort_unless(Schema::hasTable('advisor_forecast_schedules'), 503, 'Forecast schedules require the pending schema upgrade.');
    }

    private function summary(ForecastSchedule $s): array
    {
        return [...$s->toArray(), 'last_forecast_uuid' => $s->last_forecast_run_id ? ProductForecastRun::query()->whereKey($s->last_forecast_run_id)->value('uuid') : null];
    }

    public function index()
    {
        $this->ready();

        return response()->json(['data' => ForecastSchedule::query()->latest('id')->paginate(20)->through(fn ($s) => $this->summary($s))]);
    }

    public function store(Request $r, ForecastScheduleService $service, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['product_uuid' => ['required', 'uuid'], 'area_uuid' => ['required', 'uuid'], 'source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'history_weeks' => ['required', 'integer', 'min:12', 'max:104'], 'local_hour' => ['required', 'integer', 'min:0', 'max:23']]);
        Product::query()->where('uuid', $d['product_uuid'])->firstOrFail();
        Area::query()->where('uuid', $d['area_uuid'])->firstOrFail();

        return DB::transaction(function () use ($r, $d, $service, $audit) {
            $tenant = app(TenantContext::class)->requireTenant();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_if(ForecastSchedule::query()->where('product_uuid', $d['product_uuid'])->where('area_uuid', $d['area_uuid'])->where('source_system', $d['source_system'])->where('enabled', true)->exists(), 409, 'يوجد جدول نشط لهذا المنتج والمنطقة والمصدر.');
            abort_unless($service->entitled(), 403);
            $local = CarbonImmutable::now($tenant->timezone ?: 'UTC');
            $s = ForecastSchedule::query()->create([...$d, ...$service->period((int) $d['history_weeks'], $local), 'uuid' => Str::uuid(), 'owner_id' => $r->user()->id, 'next_run_at' => ReportService::nextRun('weekly', (int) $d['local_hour'], $local)]);
            $audit->record('advisor.forecast_schedule.created', ['module' => 'advisor', 'entity_id' => $s->uuid]);

            return response()->json(['data' => $this->summary($s->refresh())], 201);
        });
    }

    public function review(Request $r, string $uuid, ForecastScheduleService $service, ForecastArchiveService $archive)
    {
        $this->ready();
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'coverage_acknowledge' => ['required', 'accepted'], 'coverage_note' => ['required', 'string', 'min:12', 'max:1000']]);

        return DB::transaction(function () use ($r, $uuid, $d, $service, $archive) {
            $tenant = app(TenantContext::class)->requireTenant();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $s = ForecastSchedule::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($s->enabled && $s->review_pending && $s->version === (int) $d['version'], 409, 'حالة الجدول تغيرت؛ افتح الفترة الحالية من جديد.');
            abort_unless($service->ownerAllowed($tenant, $s) && $service->entitled(), 403);
            $period = $service->period($s->history_weeks, CarbonImmutable::now($tenant->timezone ?: 'UTC'));
            abort_unless($s->review_to->toDateString() === $period['review_to'], 409, 'الفترة القديمة انتهت؛ انتظر تجهيز الفترة الحالية من الجدولة.');
            $run = $archive->save(['product_uuid' => $s->product_uuid, 'area_uuid' => $s->area_uuid, 'source_system' => $s->source_system, 'from' => $s->review_from->toDateString(), 'to' => $s->review_to->toDateString(), 'horizon_weeks' => 2, 'coverage_note' => $d['coverage_note']], $r->user());
            abort_unless(($run->snapshot_encrypted['status'] ?? null) === 'experimental', 422, 'البيانات غير مطابقة؛ أصلح النقص قبل اعتماد فترة الجدول.');
            $s->update(['review_pending' => false, 'last_forecast_run_id' => $run->id, 'version' => $s->version + 1]);

            return response()->json(['data' => $this->summary($s)]);
        });
    }

    public function disable(Request $r, string $uuid, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['version' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($uuid, $d, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $s = ForecastSchedule::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($s->version === (int) $d['version'], 409);
            $s->update(['enabled' => false, 'version' => $s->version + 1]);
            $audit->record('advisor.forecast_schedule.disabled', ['module' => 'advisor', 'entity_id' => $s->uuid]);

            return response()->json(['data' => $this->summary($s)]);
        });
    }
}
