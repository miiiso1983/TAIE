<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\ForecastEvaluation;
use App\Modules\Advisor\Models\ProductForecastRun;
use App\Modules\Advisor\Services\ProductForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ForecastEvaluationController extends Controller
{
    private function ready(): void
    {
        abort_unless(Schema::hasTable('advisor_forecast_evaluations'), 503, 'Forecast evaluation requires the pending schema upgrade.');
    }

    public function index(string $uuid)
    {
        $this->ready();
        $run = ProductForecastRun::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => ForecastEvaluation::query()->where('forecast_run_id', $run->id)->latest('id')->paginate(20)->through(fn ($e) => [...$e->snapshot_encrypted, 'uuid' => $e->uuid, 'reviewed_at' => $e->created_at->toISOString()])]);
    }

    public function store(Request $r, string $uuid, ProductForecastService $service, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['coverage_acknowledge' => ['required', 'accepted'], 'coverage_note' => ['required', 'string', 'min:12', 'max:1000'], 'evidence_reference' => ['required', 'string', 'min:3', 'max:250']]);

        return DB::transaction(function () use ($r, $uuid, $d, $service, $audit) {
            $tenant = app(TenantContext::class)->requireTenant();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $run = ProductForecastRun::query()->where('uuid', $uuid)->firstOrFail();
            $saved = $run->snapshot_encrypted;
            abort_unless(($saved['status'] ?? null) === 'experimental' && ! empty($saved['forecast']), 422, 'لا توجد كمية توقع صالحة للمراجعة.');
            $forecast = $saved['forecast'];
            $end = CarbonImmutable::parse($forecast['to']);
            $today = CarbonImmutable::now($tenant->timezone ?: 'UTC')->startOfDay();
            abort_unless($end->lt($today), 422, 'انتظر اكتمال أسبوع التوقع قبل مراجعة النتيجة.');
            // Reuse strict source/cohort checks; never refit or overwrite the archived forecast.
            $actual = $service->analyze(['source_system' => $saved['scope']['source_system'], 'product_uuid' => $saved['product']['uuid'], 'area_uuid' => $saved['area']['uuid'], 'from' => $end->subWeeks(11)->startOfWeek()->toDateString(), 'to' => $end->toDateString(), 'coverage_note' => $d['coverage_note']]);
            abort_unless($actual['status'] === 'experimental', 422, 'سطور فترة النتيجة غير مكتملة أو غير مطابقة.');
            abort_unless($actual['product']['unit'] === $saved['product']['unit'], 422, 'وحدة المنتج تغيرت؛ راجع التحويل قبل مقارنة الكميات.');
            $actualQuantity = end($actual['series'])['sold_milli'] / 1000;
            $error = $actualQuantity - $forecast['quantity'];
            $preperiod = $run->created_at->lt(CarbonImmutable::parse($forecast['from'], $tenant->timezone ?: 'UTC'));
            $snapshot = ['forecast_uuid' => $run->uuid, 'algorithm_version' => $saved['algorithm_version'], 'product' => $saved['product'], 'area' => $saved['area'], 'forecast' => $forecast, 'actual_quantity' => $actualQuantity, 'signed_error' => round($error, 3), 'absolute_error' => round(abs($error), 3), 'inside_descriptive_interval' => $actualQuantity >= $forecast['lower'] && $actualQuantity <= $forecast['upper'], 'saved_before_period' => $preperiod, 'evaluation_kind' => $preperiod ? 'prospective_review' : 'retrospective_review', 'actual_fingerprint' => $actual['fingerprint'], 'source_authority' => $actual['source_authority'], 'coverage_note' => $d['coverage_note'], 'evidence_reference' => $d['evidence_reference'], 'reviewed_by' => $r->user()->id, 'notice' => 'مقارنة نسخة محفوظة ببيانات حالية راجع تغطيتها إنسان. لا تثبت نقطة واحدة دقة عامة أو معايرة النطاق، وقد تتغير بيانات المصدر بعد المراجعة.'];
            $hash = hash('sha256', json_encode([$run->uuid, $snapshot], JSON_THROW_ON_ERROR));
            $evaluation = ForecastEvaluation::query()->firstOrCreate(['input_hash' => $hash], ['uuid' => Str::uuid(), 'forecast_run_id' => $run->id, 'reviewed_by' => $r->user()->id, 'snapshot_encrypted' => $snapshot]);
            $audit->record('advisor.forecast.evaluated', ['module' => 'advisor', 'entity_type' => 'forecast_evaluation', 'entity_id' => $evaluation->uuid]);

            return response()->json(['data' => [...$evaluation->snapshot_encrypted, 'uuid' => $evaluation->uuid, 'reviewed_at' => $evaluation->created_at->toISOString()]]);
        });
    }
}
