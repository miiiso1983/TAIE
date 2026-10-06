<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Advisor\Services\ProductForecastService;
use Illuminate\Http\Request;

class ProductForecastController extends Controller
{
    public function analyze(Request $r, ProductForecastService $service, AuditTrailService $audit)
    {
        $today = now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString();
        $d = $r->validate(['product_uuid' => ['required', 'uuid'], 'area_uuid' => ['required', 'uuid'], 'source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before:'.$today], 'coverage_acknowledge' => ['required', 'accepted'], 'coverage_note' => ['required', 'string', 'min:12', 'max:1000']]);
        $result = $service->analyze($d);
        $audit->record('advisor.forecast.analyzed', ['module' => 'advisor', 'entity_type' => 'product_forecast', 'entity_id' => $d['product_uuid'], 'new_values' => ['fingerprint' => $result['fingerprint'], 'status' => $result['status'], 'from' => $d['from'], 'to' => $d['to']]]);

        return response()->json(['data' => $result]);
    }
}
