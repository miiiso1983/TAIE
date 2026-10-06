<?php

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Models\CoverageConfirmation;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Models\SalesReturn;
use Illuminate\Support\Facades\Schema;

class SalesQualityService
{
    public function describe(array $filters, array $evidence): array
    {
        $ids = SalesInvoice::query()->whereIn('uuid', array_column($evidence, 'uuid'))->pluck('id');
        $transactions = [];
        $totals = [];
        if (! empty($filters['currency'])) {
            $totals[$filters['currency']] = $this->emptyTotals();
        }
        foreach ($evidence as $invoice) {
            $currency = $invoice['currency'];
            $totals[$currency] ??= $this->emptyTotals();
            $totals[$currency]['invoice_count']++;
            foreach (['sales_minor', 'returns_minor', 'collected_minor', 'balance_minor', 'credit_minor'] as $field) {
                $totals[$currency][$field] = bcadd($totals[$currency][$field], $invoice[$field], 0);
            }
        }
        foreach ([SalesReceipt::class => 'receipt_date', SalesReturn::class => 'return_date'] as $class => $date) {
            $kind = $date === 'receipt_date' ? 'receipt' : 'return';
            foreach ($class::query()->whereIn('invoice_id', $ids)->whereDate($date, '<=', $filters['as_of'])->orderBy('uuid')->get() as $record) {
                $totals[$record->currency][$kind.'_count']++;
                $transactions[] = ['kind' => $kind, 'uuid' => $record->uuid, 'invoice_id' => $record->invoice_id, 'source_system' => $record->source_system, 'external_id' => $record->external_id, 'date' => $record->$date->toDateString(), 'currency' => $record->currency, 'amount_minor' => (string) $record->amount_minor, 'batch_id' => $record->batch_id];
            }
        }
        ksort($totals);
        foreach ($totals as &$total) {
            $total['difference_minor'] = bcsub(bcsub(bcsub($total['sales_minor'], $total['returns_minor'], 0), $total['collected_minor'], 0), bcsub($total['balance_minor'], $total['credit_minor'], 0), 0);
            $total['balanced'] = $total['difference_minor'] === '0';
        }
        unset($total);
        // Include identities, not only sums: replacing a transaction invalidates an attestation.
        $fingerprint = hash('sha256', json_encode([$evidence, $transactions], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $confirmation = null;
        $available = Schema::hasTable('advisor_coverage_confirmations');
        if ($available && ! empty($filters['currency']) && empty($filters['employee_uuid'])) {
            $confirmation = CoverageConfirmation::query()->where('period_from', $filters['from'])->where('period_to', $filters['to'])->where('as_of', $filters['as_of'])->where('currency', $filters['currency'])->latest('id')->first();
        }
        $status = ! $confirmation ? 'unconfirmed' : (hash_equals($confirmation->fingerprint, $fingerprint) ? 'confirmed' : 'changed');
        $batchIds = SalesInvoice::query()->whereIn('id', $ids)->pluck('batch_id')->merge(array_column($transactions, 'batch_id'))->unique();
        $sources = ImportBatch::query()->whereIn('id', $batchIds)->orderBy('id')->get()->map(fn ($batch) => ['uuid' => $batch->uuid, 'kind' => $batch->kind, 'source_system' => $batch->source_system, 'committed_at' => $batch->committed_at?->toISOString()])->all();

        return ['fingerprint' => $fingerprint, 'reconciliation' => $totals, 'coverage_confirmed' => $status === 'confirmed', 'coverage' => ['available' => $available, 'status' => $status, 'confirmation_uuid' => $confirmation?->uuid, 'confirmed_at' => $confirmation?->created_at?->toISOString(), 'basis' => 'human_attested_invoice_cohort', 'scope' => array_intersect_key($filters, array_flip(['from', 'to', 'as_of', 'currency', 'employee_uuid']))], 'sources' => $sources, 'missing' => ['invoices_without_receipts' => count(array_filter($evidence, fn ($i) => $i['receipt_count'] === 0)), 'last_invoice_date' => collect($evidence)->max('invoice_date'), 'last_receipt_date' => collect($transactions)->where('kind', 'receipt')->max('date'), 'last_return_date' => collect($transactions)->where('kind', 'return')->max('date')]];
    }

    private function emptyTotals(): array
    {
        return ['invoice_count' => 0, 'receipt_count' => 0, 'return_count' => 0, 'sales_minor' => '0', 'returns_minor' => '0', 'collected_minor' => '0', 'balance_minor' => '0', 'credit_minor' => '0'];
    }
}
