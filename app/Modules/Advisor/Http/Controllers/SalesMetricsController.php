<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\CoverageConfirmation;
use App\Modules\Advisor\Services\SalesMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

    public function confirmCoverage(Request $r, TenantContext $context, SalesMetricsService $service)
    {
        abort_unless(Schema::hasTable('advisor_coverage_confirmations'), 503, 'Coverage confirmation requires the pending schema upgrade.');
        $filters = $this->filters($r, $context);
        abort_if(empty($filters['currency']) || ! empty($filters['employee_uuid']), 422, 'Choose one currency and the entire company cohort.');
        $d = $r->validate([
            'acknowledge' => ['required', 'accepted'],
            'fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'source_note' => ['required', 'string', 'min:12', 'max:1000'],
            'expected' => ['required', 'array:invoice_count,receipt_count,return_count,sales_minor,returns_minor,collected_minor'],
            'expected.invoice_count' => ['required', 'integer', 'min:0'],
            'expected.receipt_count' => ['required', 'integer', 'min:0'],
            'expected.return_count' => ['required', 'integer', 'min:0'],
            'expected.sales_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/'],
            'expected.returns_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/'],
            'expected.collected_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/'],
        ]);
        $confirmation = DB::transaction(function () use ($r, $context, $service, $filters, $d) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $quality = $service->dataset($filters)['quality'];
            abort_unless(hash_equals($quality['fingerprint'], $d['fingerprint']), 409, 'Data changed; refresh and reconcile again.');
            $total = $quality['reconciliation'][$filters['currency']];
            foreach ($d['expected'] as $key => $value) {
                abort_unless((string) $total[$key] === (string) $value, 422, 'Source count or amount does not match: '.$key);
            }
            abort_unless($total['balanced'], 422, 'Reconciliation is not balanced.');
            $confirmation = CoverageConfirmation::query()->create(['uuid' => Str::uuid(), 'period_from' => $filters['from'], 'period_to' => $filters['to'], 'as_of' => $filters['as_of'], 'currency' => $filters['currency'], 'fingerprint' => $quality['fingerprint'], 'review_encrypted' => ['source_note' => $d['source_note'], 'expected' => $d['expected'], 'sources' => $quality['sources']], 'created_by' => $r->user()->id]);
            app(AuditTrailService::class)->record('advisor.coverage.confirmed', ['module' => 'advisor', 'entity_type' => 'coverage_confirmation', 'entity_id' => $confirmation->uuid, 'new_values' => ['currency' => $filters['currency']]]);

            return $confirmation;
        });

        return response()->json(['data' => $confirmation], 201);
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
