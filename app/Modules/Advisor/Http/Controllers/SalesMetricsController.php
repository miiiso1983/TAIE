<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Advisor\Services\SalesMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SalesMetricsController extends Controller
{
    private function filters(Request $r, TenantContext $context): array
    {
        $today = now($context->requireTenant()->timezone ?: 'UTC')->toDateString();
        $d = $r->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:to', 'before_or_equal:'.$today], 'currency' => ['nullable', 'in:USD,IQD'], 'employee_uuid' => ['nullable', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1']]);
        abort_if(CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) > 366, 422, 'The reporting period must be at most 367 days.');

        return $d;
    }

    public function metrics(Request $r, TenantContext $context, SalesMetricsService $service)
    {
        $d = $this->filters($r, $context);
        $result = $service->dataset($d);

        return response()->json(['data' => ['filters' => $d, 'generated_at' => now()->toISOString(), 'basis' => 'invoice_cohort_collections_through_as_of', 'rows' => $result['rows'], 'quality' => $result['quality']]]);
    }

    public function invoices(Request $r, TenantContext $context, SalesMetricsService $service)
    {
        $d = $this->filters($r, $context);
        $rows = $service->dataset($d)['evidence'];
        $page = (int) ($d['page'] ?? 1);
        $total = count($rows);

        return response()->json(['data' => array_slice($rows, ($page - 1) * 50, 50), 'meta' => ['total' => $total, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / 50))]]);
    }
}
