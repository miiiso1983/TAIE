<?php

namespace App\Modules\Advisor\Services;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Models\SalesReturn;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordReconciliationService
{
    public const HEADERS = ['kind', 'external_id', 'invoice_external_id', 'record_date', 'due_date', 'customer_number', 'employee_number', 'currency', 'amount'];

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    public function parse(string $path, string $format, int $sheet, array $scope): array
    {
        $table = app(TabularFileService::class)->read($path, $format, $sheet);
        if ($table['header'] !== self::HEADERS) {
            $this->fail('استخدم أعمدة قالب مطابقة السجلات بالترتيب نفسه.');
        }
        $records = $seen = $invoices = [];
        $code = ['required', 'string', 'max:100', 'regex:/^[\pL\pN][\pL\pN._\/\-]*$/u'];
        foreach ($table['rows'] as $row) {
            $d = array_combine(self::HEADERS, $row['cells']);
            $invoice = $d['kind'] === 'invoices';
            $rules = ['kind' => ['required', 'in:invoices,receipts,returns'], 'external_id' => $code, 'record_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$scope['as_of']], 'currency' => ['required', 'in:'.$scope['currency']], 'amount' => ['required', 'string']];
            if ($invoice) {
                $rules += ['due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:record_date'], 'customer_number' => [...$code, 'max:60'], 'employee_number' => [...$code, 'max:60'], 'invoice_external_id' => ['prohibited']];
                $rules['record_date'][] = 'after_or_equal:'.$scope['from'];
                $rules['record_date'][] = 'before_or_equal:'.$scope['to'];
            } else {
                $rules += ['invoice_external_id' => $code, 'due_date' => ['prohibited'], 'customer_number' => ['prohibited'], 'employee_number' => ['prohibited']];
            }
            $v = Validator::make($d, $rules);
            $amount = app(BusinessDataImportService::class)->amount($d['amount'], $scope['currency']);
            if ($v->fails() || $amount === null) {
                $this->fail('السجل '.$row['line'].' غير صالح: '.implode('، ', array_keys($v->errors()->toArray())).($amount === null ? '، amount' : ''));
            }
            $key = $d['kind'].'|'.$d['external_id'];
            if (isset($seen[$key])) {
                $this->fail('معرّف مكرر داخل نوع السجل في السطر '.$row['line'].'.');
            }
            $seen[$key] = true;
            unset($d['amount']);
            $d['amount_minor'] = (string) $amount;
            $records[] = $d;
            if ($invoice) {
                $invoices[$d['external_id']] = $d['record_date'];
            }
        }
        if (! $invoices) {
            $this->fail('يجب أن يحتوي التقرير فواتير النطاق وتحصلاتها ومرتجعاتها.');
        }
        foreach ($records as $d) {
            if ($d['kind'] !== 'invoices' && (! isset($invoices[$d['invoice_external_id']]) || $d['record_date'] < $invoices[$d['invoice_external_id']])) {
                $this->fail('الحركة '.$d['external_id'].' تحتاج فاتورة داخل تقرير المصدر وتاريخاً لا يسبق فاتورتها.');
            }
        }

        return $this->sort($records);
    }

    private function sort(array $records): array
    {
        usort($records, fn ($a, $b) => strcmp($a['kind'].'|'.$a['external_id'], $b['kind'].'|'.$b['external_id']));

        return $records;
    }

    // Include expected identities outside the normal date/currency cohort so a changed
    // date or parent is reported as a field difference, rather than a missing record.
    public function snapshot(array $scope, array $expected): array
    {
        return DB::transaction(function () use ($scope, $expected) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();

            return $this->capture($scope, $expected);
        });
    }

    private function capture(array $scope, array $expected): array
    {
        $ids = fn ($kind) => array_column(array_filter($expected, fn ($r) => $r['kind'] === $kind), 'external_id');
        $q = SalesInvoice::query()->where('source_system', $scope['source_system'])->where(function ($q) use ($scope, $ids) {
            $q->where(function ($q) use ($scope) {
                $q->where('currency', $scope['currency'])->whereDate('invoice_date', '>=', $scope['from'])->whereDate('invoice_date', '<=', $scope['to']);
            })->orWhereIn('external_id', $ids('invoices'));
        });
        abort_if((clone $q)->count() > 10000, 422, 'Narrow the scope: maximum 10000 invoices.');
        $invoices = $q->orderBy('id')->get();
        $cohort = $invoices->filter(fn ($i) => $i->currency === $scope['currency'] && $i->invoice_date->toDateString() >= $scope['from'] && $i->invoice_date->toDateString() <= $scope['to']);
        $customers = Customer::withTrashed()->whereIn('id', $invoices->pluck('customer_id'))->pluck('customer_number', 'id');
        $employees = Employee::withTrashed()->whereIn('id', $invoices->pluck('employee_id'))->pluck('employee_number', 'id');
        $records = [];
        foreach ($invoices as $i) {
            $records[] = ['kind' => 'invoices', 'external_id' => $i->external_id, 'invoice_external_id' => '', 'record_date' => $i->invoice_date->toDateString(), 'due_date' => $i->due_date->toDateString(), 'customer_number' => $customers[$i->customer_id] ?? null, 'employee_number' => $employees[$i->employee_id] ?? null, 'currency' => $i->currency, 'amount_minor' => (string) $i->net_amount_minor];
        }
        foreach (['receipts' => [SalesReceipt::class, 'receipt_date'], 'returns' => [SalesReturn::class, 'return_date']] as $kind => [$model, $date]) {
            $query = $model::query()->where('source_system', $scope['source_system'])->where(function ($q) use ($cohort, $scope, $ids, $kind, $date) {
                $q->where(function ($q) use ($cohort, $scope, $date) {
                    $q->whereIn('invoice_id', $cohort->pluck('id'))->whereDate($date, '<=', $scope['as_of']);
                })->orWhereIn('external_id', $ids($kind));
            });
            abort_if((clone $query)->count() > 10000, 422, 'Narrow the scope: maximum 10000 movements of each type.');
            $movements = $query->orderBy('id')->get();
            $parents = SalesInvoice::query()->whereIn('id', $movements->pluck('invoice_id'))->pluck('external_id', 'id');
            foreach ($movements as $m) {
                $records[] = ['kind' => $kind, 'external_id' => $m->external_id, 'invoice_external_id' => $parents[$m->invoice_id] ?? null, 'record_date' => $m->$date->toDateString(), 'due_date' => '', 'customer_number' => '', 'employee_number' => '', 'currency' => $m->currency, 'amount_minor' => (string) $m->amount_minor];
            }
        }
        $records = $this->sort($records);
        $base = app(ReconciliationService::class)->snapshot($scope);

        return [...$base, 'fingerprint' => hash('sha256', json_encode([$scope, $records], JSON_THROW_ON_ERROR)), 'records' => $records];
    }

    public function compare(array $expected, array $actual): array
    {
        $index = fn ($rows) => array_column(array_map(fn ($r) => [...$r, '_key' => $r['kind'].'|'.$r['external_id']], $rows), null, '_key');
        $left = $index($expected);
        $right = $index($actual);
        $counts = ['matched' => 0, 'missing_in_teami' => 0, 'unexpected_in_teami' => 0, 'changed_fields' => 0];
        $differences = [];
        $keys = array_unique([...array_keys($left), ...array_keys($right)]);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $e = $left[$key] ?? null;
            $a = $right[$key] ?? null;
            $fields = [];
            if (! $a) {
                $type = 'missing_in_teami';
            } elseif (! $e) {
                $type = 'unexpected_in_teami';
            } else {
                foreach (array_diff(self::HEADERS, ['kind', 'external_id', 'amount']) as $field) {
                    if ($e[$field] !== $a[$field]) {
                        $fields[$field] = ['source' => $e[$field], 'imported' => $a[$field]];
                    }
                }
                if ($e['amount_minor'] !== $a['amount_minor']) {
                    $fields['amount_minor'] = ['source' => $e['amount_minor'], 'imported' => $a['amount_minor']];
                }
                $type = $fields ? 'changed_fields' : 'matched';
            }
            $counts[$type]++;
            if ($type !== 'matched') {
                unset($e['_key'], $a['_key']);
                $differences[] = ['kind' => ($e ?? $a)['kind'], 'external_id' => ($e ?? $a)['external_id'], 'type' => $type, 'fields' => $fields, 'source' => $e, 'imported' => $a];
            }
        }
        $totals = array_fill_keys(['invoices', 'receipts', 'returns'], ['count' => 0, 'amount_minor' => '0']);
        foreach ($expected as $r) {
            $totals[$r['kind']]['count']++;
            $totals[$r['kind']]['amount_minor'] = bcadd($totals[$r['kind']]['amount_minor'], $r['amount_minor'], 0);
        }

        return ['status' => count($differences) ? 'mismatched' : 'matched', 'record_counts' => $counts, 'record_differences' => $differences, 'expected' => $totals];
    }
}
