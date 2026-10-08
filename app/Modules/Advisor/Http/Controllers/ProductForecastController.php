<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\ProductForecastRun;
use App\Modules\Advisor\Services\ForecastArchiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductForecastController extends Controller
{
    public function analyze(Request $r)
    {
        $today = now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString();
        $d = $r->validate(['horizon_weeks' => ['sometimes', 'integer', 'in:1,2'], 'product_uuid' => ['required', 'uuid'], 'area_uuid' => ['required', 'uuid'], 'source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before:'.$today], 'coverage_acknowledge' => ['required', 'accepted'], 'coverage_note' => ['required', 'string', 'min:12', 'max:1000']]);
        $d['horizon_weeks'] = (int) ($d['horizon_weeks'] ?? 1);
        $this->ready();

        return DB::transaction(function () use ($r, $d) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $run = app(ForecastArchiveService::class)->save($d, $r->user());

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
