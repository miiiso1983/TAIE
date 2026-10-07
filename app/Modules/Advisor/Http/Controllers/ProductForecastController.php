<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\ProductForecastRun;
use App\Modules\Advisor\Services\ProductForecastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductForecastController extends Controller
{
    public function analyze(Request $r, ProductForecastService $service, AuditTrailService $audit)
    {
        $today = now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString();
        $d = $r->validate(['product_uuid' => ['required', 'uuid'], 'area_uuid' => ['required', 'uuid'], 'source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before:'.$today], 'coverage_acknowledge' => ['required', 'accepted'], 'coverage_note' => ['required', 'string', 'min:12', 'max:1000']]);
        $this->ready();

        return DB::transaction(function () use ($r, $d, $service, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $result = $service->analyze($d);
            $hash = hash('sha256', json_encode([$result['fingerprint'], $result['algorithm_version'], $d['coverage_note'], $r->user()->id], JSON_THROW_ON_ERROR));
            $run = ProductForecastRun::query()->firstOrCreate(['input_hash' => $hash], ['uuid' => Str::uuid(), 'fingerprint' => $result['fingerprint'], 'created_by' => $r->user()->id, 'snapshot_encrypted' => $result]);
            $audit->record('advisor.forecast.analyzed', ['module' => 'advisor', 'entity_type' => 'product_forecast', 'entity_id' => $run->uuid, 'new_values' => ['fingerprint' => $result['fingerprint'], 'status' => $result['status'], 'from' => $d['from'], 'to' => $d['to']]]);

            return response()->json(['data' => $this->snapshot($run)]);
        });
    }

    private function ready(): void
    {
        abort_unless(Schema::hasTable('advisor_product_forecast_runs'), 503, 'Forecast archive requires the pending schema upgrade.');
    }

    private function snapshot(ProductForecastRun $run): array
    {
        return [...$run->snapshot_encrypted, 'uuid' => $run->uuid, 'saved_at' => $run->created_at->toISOString(), 'coverage_reviewer_id' => $run->created_by, 'snapshot_notice' => 'نسخة محفوظة بتاريخها؛ قد تكون البيانات الحالية تغيرت. أعد التحليل لفحص النسخة الحالية.'];
    }

    public function index()
    {
        $this->ready();

        return response()->json(['data' => ProductForecastRun::query()->latest('id')->paginate(20)->through(function ($run) {
            $s = $run->snapshot_encrypted;

            return ['uuid' => $run->uuid, 'saved_at' => $run->created_at->toISOString(), 'product_code' => $s['product']['code'], 'area_code' => $s['area']['code'], 'scope' => $s['scope'], 'status' => $s['status']];
        })]);
    }

    public function show(string $uuid)
    {
        $this->ready();

        return response()->json(['data' => $this->snapshot(ProductForecastRun::query()->where('uuid', $uuid)->firstOrFail())]);
    }
}
