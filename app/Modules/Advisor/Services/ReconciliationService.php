<?php

namespace App\Modules\Advisor\Services;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Advisor\Models\InvoiceLine;
use App\Modules\Advisor\Models\Reconciliation;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Models\SalesReturn;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function snapshot(array $scope): array
    {
        return DB::transaction(function () use ($scope) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $q = SalesInvoice::query()->where('source_system', $scope['source_system'])->where('currency', $scope['currency'])->whereDate('invoice_date', '>=', $scope['from'])->whereDate('invoice_date', '<=', $scope['to']);
            abort_if((clone $q)->count() > 10000, 422, 'Narrow the scope: maximum 10000 invoices.');
            $invoices = $q->orderBy('id')->get(['id', 'uuid', 'external_id', 'invoice_date', 'due_date', 'net_amount_minor', 'customer_id', 'employee_id', 'area_id']);
            $sets = ['invoices' => $invoices];
            foreach (['receipts' => [SalesReceipt::class, 'receipt_date'], 'returns' => [SalesReturn::class, 'return_date']] as $kind => [$model, $date]) {
                $query = $model::query()->whereIn('invoice_id', $invoices->pluck('id'))->whereDate($date, '<=', $scope['as_of']);
                abort_if((clone $query)->count() > 50000, 422, 'Narrow the scope: maximum 50000 movements of each type.');
                $sets[$kind] = $query->orderBy('id')->get(['uuid', 'external_id', 'invoice_id', $date, 'amount_minor']);
            }
            $actual = [];
            $evidence = [];
            foreach ($sets as $kind => $records) {
                $amount = '0';
                $field = $kind === 'invoices' ? 'net_amount_minor' : 'amount_minor';
                foreach ($records as $record) {
                    $amount = bcadd($amount, (string) $record->$field, 0);
                }
                $actual[$kind] = ['count' => $records->count(), 'amount_minor' => $amount];
                $evidence[$kind] = $records->toArray();
            }
            $lineSums = InvoiceLine::query()->whereIn('invoice_id', $invoices->pluck('id'))->selectRaw('invoice_id, SUM(net_amount_minor) AS total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
            $readiness = ['invoice_count' => $invoices->count(), 'invoices_without_area' => 0, 'invoices_without_lines' => 0, 'invoices_with_line_difference' => 0];
            foreach ($invoices as $invoice) {
                $readiness['invoices_without_area'] += $invoice->area_id === null ? 1 : 0;
                if (! $lineSums->has($invoice->id)) {
                    $readiness['invoices_without_lines']++;
                } elseif (bccomp((string) $lineSums[$invoice->id], (string) $invoice->net_amount_minor, 0) !== 0) {
                    $readiness['invoices_with_line_difference']++;
                }
            }

            return ['actual' => $actual, 'fingerprint' => hash('sha256', json_encode([$scope, $evidence], JSON_THROW_ON_ERROR)), 'readiness' => $readiness];
        });
    }

    public function compare(array $expected, array $actual): array
    {
        $differences = [];
        $matched = true;
        foreach ($actual as $kind => $value) {
            $count = $value['count'] - $expected[$kind]['count'];
            $amount = bcsub($value['amount_minor'], $expected[$kind]['amount_minor'], 0);
            $differences[$kind] = ['count' => $count, 'amount_minor' => $amount];
            $matched = $matched && $count === 0 && bccomp($amount, '0', 0) === 0;
        }

        return ['status' => $matched ? 'matched' : 'mismatched', 'differences' => $differences];
    }

    public function detail(Reconciliation $report): array
    {
        $saved = $report->snapshot_encrypted;
        $detailed = $report->method === 'record_matching';
        $current = $detailed ? app(RecordReconciliationService::class)->snapshot($saved['scope'], $saved['expected_records']) : $this->snapshot($saved['scope']);
        unset($current['records'], $saved['expected_records'], $saved['actual_records'], $saved['record_differences']);

        return [...$this->summary($report), 'current_status' => hash_equals($report->fingerprint, $current['fingerprint']) ? $report->status : 'stale', 'snapshot' => $saved, 'current' => $current, 'evidence_reference' => $report->evidence_reference_encrypted, 'review_note' => $report->review_note_encrypted, 'coverage_confirmed' => false];
    }

    public function summary(Reconciliation $r): array
    {
        return ['method' => $r->method, 'uuid' => $r->uuid, 'source_system' => $r->source_system, 'from' => $r->period_from->toDateString(), 'to' => $r->period_to->toDateString(), 'as_of' => $r->as_of->toDateString(), 'currency' => $r->currency, 'status' => $r->status, 'created_at' => $r->created_at->toISOString()];
    }
}
