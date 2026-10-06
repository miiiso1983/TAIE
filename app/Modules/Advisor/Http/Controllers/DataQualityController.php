<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\CustomerArea;
use App\Modules\Advisor\Models\InventoryObservation;
use App\Modules\Advisor\Models\InvoiceLine;
use App\Modules\Advisor\Models\Product;
use App\Modules\Advisor\Models\Reconciliation;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Services\BusinessDataImportService;
use App\Modules\Advisor\Services\InventoryImportService;
use App\Modules\Advisor\Services\ReconciliationService;
use App\Modules\Advisor\Services\RecordReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DataQualityController extends Controller
{
    private function ensureReady(): void
    {
        abort_unless(BusinessDataImportService::available() && Schema::hasTable('advisor_reconciliations') && Schema::hasColumn('advisor_reconciliations', 'method'), 503, 'Data quality requires the pending schema upgrade.');
    }

    public function catalog(Request $r): mixed
    {
        $this->ensureReady();
        $d = $r->validate(['kind' => ['required', 'in:products,areas,customer_locations,invoice_lines'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $data = match ($d['kind']) {
            'products' => Product::query()->orderBy('code')->paginate(50)->through(fn ($p) => ['uuid' => $p->uuid, 'code' => $p->code, 'name' => $p->name, 'base_unit' => $p->base_unit]),
            'areas' => Area::query()->orderBy('code')->paginate(50)->through(fn ($a) => ['uuid' => $a->uuid, 'code' => $a->code, 'country_code' => $a->country_code, 'province_name' => $a->province_name, 'city_name' => $a->city_name]),
            'customer_locations' => CustomerArea::query()->join('sales_customers AS c', function ($join) {
                $join->on('c.id', 'advisor_customer_areas.customer_id')->on('c.tenant_id', 'advisor_customer_areas.tenant_id');
            })->join('advisor_areas AS a', function ($join) {
                $join->on('a.id', 'advisor_customer_areas.area_id')->on('a.tenant_id', 'advisor_customer_areas.tenant_id');
            })->select(['advisor_customer_areas.uuid', 'c.customer_number', 'c.name', 'a.code AS area_code', 'a.province_name', 'a.city_name'])->orderBy('advisor_customer_areas.id')->paginate(50),
            'invoice_lines' => InvoiceLine::query()->join('advisor_sales_invoices AS i', function ($join) {
                $join->on('i.id', 'advisor_invoice_lines.invoice_id')->on('i.tenant_id', 'advisor_invoice_lines.tenant_id');
            })->join('advisor_products AS p', function ($join) {
                $join->on('p.id', 'advisor_invoice_lines.product_id')->on('p.tenant_id', 'advisor_invoice_lines.tenant_id');
            })->select(['advisor_invoice_lines.uuid', 'advisor_invoice_lines.source_system', 'advisor_invoice_lines.external_id', 'i.external_id AS invoice_external_id', 'p.code AS product_code', 'advisor_invoice_lines.quantity', 'advisor_invoice_lines.base_unit', 'advisor_invoice_lines.net_amount_minor', 'i.currency'])->orderBy('advisor_invoice_lines.id')->paginate(50)->through(fn ($x) => [...$x->toArray(), 'net_amount_minor' => (string) $x->net_amount_minor]),
        };

        return response()->json(['data' => $data]);
    }

    public function inventory(Request $r): mixed
    {
        abort_unless(InventoryImportService::available(), 503, 'Inventory inputs require the pending schema upgrade.');
        $d = $r->validate(['kind' => ['required', 'in:stock_snapshots,product_costs,stockouts,return_lines']]);
        $data = InventoryObservation::query()->where('kind', $d['kind'])
            ->join('advisor_products as p', function ($j) {
                $j->on('p.id', 'advisor_inventory_observations.product_id')->on('p.tenant_id', 'advisor_inventory_observations.tenant_id');
            })
            ->leftJoin('advisor_areas as a', function ($j) {
                $j->on('a.id', 'advisor_inventory_observations.area_id')->on('a.tenant_id', 'advisor_inventory_observations.tenant_id');
            })
            ->select('advisor_inventory_observations.*', 'p.code as product_code', 'p.base_unit', 'a.code as area_code')->orderByDesc('advisor_inventory_observations.id')->paginate(50)
            ->through(fn ($x) => [...$x->toArray(), 'amount_minor' => $x->amount_minor === null ? null : (string) $x->amount_minor]);

        return response()->json(['data' => $data]);
    }

    public function sources(): mixed
    {
        $this->ensureReady();

        return response()->json(['data' => SalesInvoice::query()->select('source_system')->distinct()->orderBy('source_system')->pluck('source_system')]);
    }

    public function index(Request $r, ReconciliationService $service): mixed
    {
        $this->ensureReady();
        $r->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(['data' => Reconciliation::query()->latest('id')->paginate(20)->through(fn ($r) => $service->summary($r))]);
    }

    public function show(string $uuid, ReconciliationService $service): mixed
    {
        $this->ensureReady();

        return response()->json(['data' => $service->detail(Reconciliation::query()->where('uuid', $uuid)->firstOrFail())]);
    }

    public function differences(Request $r, string $uuid): mixed
    {
        $this->ensureReady();
        $d = $r->validate(['page' => ['sometimes', 'integer', 'min:1'], 'kind' => ['sometimes', 'in:invoices,receipts,returns'], 'type' => ['sometimes', 'in:missing_in_teami,unexpected_in_teami,changed_fields']]);
        $report = Reconciliation::query()->where('uuid', $uuid)->where('method', 'record_matching')->firstOrFail();
        $rows = collect($report->snapshot_encrypted['record_differences'])->filter(fn ($x) => (! isset($d['kind']) || $x['kind'] === $d['kind']) && (! isset($d['type']) || $x['type'] === $d['type']))->values();
        $page = (int) ($d['page'] ?? 1);

        return response()->json(['data' => ['data' => $rows->slice(($page - 1) * 50, 50)->values(), 'total' => $rows->count(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / 50)), 'per_page' => 50], 'basis' => 'saved_comparison']);
    }

    public function detailed(Request $r, TenantContext $context, ReconciliationService $service, RecordReconciliationService $records, AuditTrailService $audit): mixed
    {
        $this->ensureReady();
        $today = now($context->requireTenant()->timezone ?: 'UTC')->toDateString();
        $d = $r->validate(['source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:to', 'before_or_equal:'.$today], 'currency' => ['required', 'in:USD,IQD'], 'evidence_reference' => ['required', 'string', 'min:3', 'max:255'], 'review_note' => ['required', 'string', 'min:12', 'max:1000'], 'acknowledge' => ['required', 'accepted'], 'file' => ['required', 'file', 'max:1024'], 'sheet' => ['sometimes', 'integer', 'min:0', 'max:9']]);
        abort_if(CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) > 366, 422, 'Maximum period is 367 days.');
        $scope = array_intersect_key($d, array_flip(['source_system', 'from', 'to', 'as_of', 'currency']));
        $file = $r->file('file');
        $expected = $records->parse($file->getRealPath(), strtolower($file->getClientOriginalExtension()), (int) ($d['sheet'] ?? 0), $scope);
        $report = DB::transaction(function () use ($scope, $expected, $records, $service, $context, $d, $r, $file, $audit) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            abort_unless(SalesInvoice::query()->where('source_system', $scope['source_system'])->exists(), 404, 'Source has no imported invoices in this company.');
            $current = $records->snapshot($scope, $expected);
            $result = $records->compare($expected, $current['records']);
            $totals = $service->compare($result['expected'], $current['actual']);
            $report = Reconciliation::query()->create(['uuid' => (string) Str::uuid(), 'method' => 'record_matching', 'source_system' => $scope['source_system'], 'period_from' => $scope['from'], 'period_to' => $scope['to'], 'as_of' => $scope['as_of'], 'currency' => $scope['currency'], 'status' => $result['status'], 'fingerprint' => $current['fingerprint'], 'snapshot_encrypted' => ['scope' => $scope, 'expected' => $result['expected'], 'actual' => $current['actual'], 'differences' => $totals['differences'], 'totals_status' => $totals['status'], 'record_counts' => $result['record_counts'], 'record_differences' => $result['record_differences'], 'expected_records' => $expected, 'actual_records' => $current['records'], 'file_sha256' => hash_file('sha256', $file->getRealPath()), 'readiness' => $current['readiness'], 'basis' => 'invoice_cohort_plus_expected_identities', 'engine_version' => 1], 'evidence_reference_encrypted' => $d['evidence_reference'], 'review_note_encrypted' => $d['review_note'], 'created_by' => $r->user()->id]);
            $audit->record('advisor.data.records_reconciled', ['module' => 'advisor', 'entity_type' => 'reconciliation', 'entity_id' => $report->uuid, 'new_values' => ['status' => $report->status, 'source_system' => $report->source_system]]);

            return $report;
        });

        return response()->json(['data' => $service->detail($report)], 201);
    }

    public function store(Request $r, TenantContext $context, ReconciliationService $service, AuditTrailService $audit): mixed
    {
        $this->ensureReady();
        $today = now($context->requireTenant()->timezone ?: 'UTC')->toDateString();
        $rules = ['source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:to', 'before_or_equal:'.$today], 'currency' => ['required', 'in:USD,IQD'], 'evidence_reference' => ['required', 'string', 'min:3', 'max:255'], 'review_note' => ['required', 'string', 'min:12', 'max:1000'], 'acknowledge' => ['required', 'accepted']];
        foreach (['invoice', 'receipt', 'return'] as $kind) {
            $rules[$kind.'_count'] = ['required', 'integer', 'min:0', 'max:50000'];
            $rules[$kind.'_amount'] = ['required', 'string', 'max:24'];
        }
        $d = $r->validate($rules);
        abort_if(CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) > 366, 422, 'Maximum period is 367 days.');
        $expected = [];
        foreach (['invoice' => 'invoices', 'receipt' => 'receipts', 'return' => 'returns'] as $singular => $plural) {
            $digits = $d['currency'] === 'IQD' ? 3 : 2;
            $raw = $d[$singular.'_amount'];
            $valid = preg_match('/^(0|[1-9][0-9]{0,17})(?:\.([0-9]{1,'.$digits.'}))?$/D', $raw, $parts);
            $value = $valid ? bcadd(bcmul($parts[1], (string) (10 ** $digits), 0), str_pad($parts[2] ?? '', $digits, '0'), 0) : null;
            if ($value === null) {
                throw ValidationException::withMessages([$singular.'_amount' => 'أدخل مبلغاً غير سالب بدقة العملة ودون فواصل آلاف.']);
            }
            $expected[$plural] = ['count' => (int) $d[$singular.'_count'], 'amount_minor' => (string) $value];
        }
        $scope = array_intersect_key($d, array_flip(['source_system', 'from', 'to', 'as_of', 'currency']));
        $report = DB::transaction(function () use ($context, $r, $d, $scope, $expected, $service, $audit) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            abort_unless(SalesInvoice::query()->where('source_system', $scope['source_system'])->exists(), 404, 'Source has no imported invoices in this company.');
            $actual = $service->snapshot($scope);
            $result = $service->compare($expected, $actual['actual']);
            $report = Reconciliation::query()->create(['uuid' => (string) Str::uuid(), 'source_system' => $scope['source_system'], 'period_from' => $scope['from'], 'period_to' => $scope['to'], 'as_of' => $scope['as_of'], 'currency' => $scope['currency'], 'status' => $result['status'], 'fingerprint' => $actual['fingerprint'], 'snapshot_encrypted' => ['scope' => $scope, 'expected' => $expected, 'actual' => $actual['actual'], 'differences' => $result['differences'], 'readiness' => $actual['readiness'], 'basis' => 'invoice_cohort_movements_through_as_of', 'engine_version' => 1], 'evidence_reference_encrypted' => $d['evidence_reference'], 'review_note_encrypted' => $d['review_note'], 'created_by' => $r->user()->id]);
            $audit->record('advisor.data.reconciled', ['module' => 'advisor', 'entity_type' => 'reconciliation', 'entity_id' => $report->uuid, 'new_values' => ['status' => $report->status, 'source_system' => $report->source_system]]);

            return $report;
        });

        return response()->json(['data' => $service->detail($report)], 201);
    }
}
