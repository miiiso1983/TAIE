<?php

namespace App\Modules\Advisor\Services;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Models\SalesReturn;
use App\Modules\Advisor\Models\SalesTarget;
use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;

class SalesMetricsService
{
    public function dataset(array $d): array
    {
        // A single tenant snapshot prevents mixing the two sides of an import while calculating.
        return DB::transaction(function () use ($d) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $q = SalesInvoice::query()->whereDate('invoice_date', '>=', $d['from'])->whereDate('invoice_date', '<=', $d['to']);
            if (! empty($d['currency'])) {
                $q->where('currency', $d['currency']);
            }
            if (! empty($d['employee_uuid'])) {
                $id = Employee::withTrashed()->where('uuid', $d['employee_uuid'])->value('id');
                abort_unless($id, 404, 'Employee not found.');
                $q->where('employee_id', $id);
            }
            abort_if((clone $q)->count() > 10000, 422, 'Narrow the period or employee filter; the limit is 10000 invoices.');
            $invoices = $q->orderBy('invoice_date')->orderBy('id')->get();
            $paid = SalesReceipt::query()->whereIn('invoice_id', $invoices->pluck('id'))->whereDate('receipt_date', '<=', $d['as_of'])->selectRaw('invoice_id, SUM(amount_minor) AS total, COUNT(*) AS receipt_count')->groupBy('invoice_id')->get()->keyBy('invoice_id');
            $returns = SalesReturn::query()->whereIn('invoice_id', $invoices->pluck('id'))->whereDate('return_date', '<=', $d['as_of'])->selectRaw('invoice_id, SUM(amount_minor) AS total')->groupBy('invoice_id')->get()->keyBy('invoice_id');
            $targetQuery = SalesTarget::query()->whereDate('period_from', $d['from'])->whereDate('period_to', $d['to']);
            if (! empty($d['currency'])) {
                $targetQuery->where('currency', $d['currency']);
            }
            if (! empty($d['employee_uuid'])) {
                $targetQuery->where('employee_id', $id);
            }
            abort_if((clone $targetQuery)->count() > 10000, 422, 'Narrow the period or employee filter; the limit is 10000 targets.');
            $targets = $targetQuery->get()->keyBy(fn ($target) => $target->employee_id.':'.$target->currency);
            $employees = Employee::withTrashed()->whereIn('id', $invoices->pluck('employee_id')->merge($targets->pluck('employee_id'))->unique())->get()->keyBy('id');
            $employeesByUuid = $employees->keyBy('uuid');
            $evidence = [];
            $groups = [];
            $receiptCount = 0;
            foreach ($targets as $target) {
                $employee = $employees[$target->employee_id];
                $groups[$employee->uuid.':'.$target->currency] = ['employee_uuid' => $employee->uuid, 'employee_number' => $employee->employee_number, 'employee_name' => $employee->first_name.' '.$employee->last_name, 'currency' => $target->currency, 'invoice_count' => 0, 'invoices_without_recorded_receipts' => 0, 'sales_minor' => 0, 'returns_minor' => 0, 'net_sales_minor' => 0, 'credit_minor' => 0, 'collected_minor' => 0, 'balance_minor' => 0, 'overdue_balance_minor' => 0];
            }
            foreach ($invoices as $i) {
                $collected = (int) ($paid[$i->id]->total ?? 0);
                $count = (int) ($paid[$i->id]->receipt_count ?? 0);
                $receiptCount += $count;
                $returned = (int) ($returns[$i->id]->total ?? 0);
                $net = $i->net_amount_minor - $returned;
                $balance = max(0, $net - $collected);
                $credit = max(0, $collected - $net);
                $overdue = $i->due_date->toDateString() < $d['as_of'] ? max(0, $balance) : 0;
                $employee = $employees[$i->employee_id];
                $key = $employee->uuid.':'.$i->currency;
                $groups[$key] ??= ['employee_uuid' => $employee->uuid, 'employee_number' => $employee->employee_number, 'employee_name' => $employee->first_name.' '.$employee->last_name, 'currency' => $i->currency, 'invoice_count' => 0, 'invoices_without_recorded_receipts' => 0, 'sales_minor' => 0, 'returns_minor' => 0, 'net_sales_minor' => 0, 'credit_minor' => 0, 'collected_minor' => 0, 'balance_minor' => 0, 'overdue_balance_minor' => 0];
                $g = &$groups[$key];
                $g['invoice_count']++;
                $g['invoices_without_recorded_receipts'] += $count === 0 ? 1 : 0;
                $g['sales_minor'] += $i->net_amount_minor;
                $g['returns_minor'] += $returned;
                $g['net_sales_minor'] += $net;
                $g['credit_minor'] += $credit;
                $g['collected_minor'] += $collected;
                $g['balance_minor'] += $balance;
                $g['overdue_balance_minor'] += $overdue;
                unset($g);
                $evidence[] = ['uuid' => $i->uuid, 'external_id' => $i->external_id, 'source_system' => $i->source_system, 'invoice_date' => $i->invoice_date->toDateString(), 'due_date' => $i->due_date->toDateString(), 'employee_uuid' => $employee->uuid, 'employee_name' => $employee->first_name.' '.$employee->last_name, 'currency' => $i->currency, 'sales_minor' => (string) $i->net_amount_minor, 'returns_minor' => (string) $returned, 'net_sales_minor' => (string) $net, 'credit_minor' => (string) $credit, 'collected_minor' => (string) $collected, 'balance_minor' => (string) $balance, 'overdue_balance_minor' => (string) $overdue, 'receipt_count' => $count];
            }
            $rows = [];
            foreach ($groups as $g) {
                $g['collection_percent'] = $g['net_sales_minor'] > 0 ? bcdiv(bcmul((string) $g['collected_minor'], '100', 2), (string) $g['net_sales_minor'], 2) : null;
                $employeeId = $employeesByUuid[$g['employee_uuid']]->id;
                $target = $targets[$employeeId.':'.$g['currency']] ?? null;
                $g['target_minor'] = $target ? (string) $target->amount_minor : null;
                $g['target_uuid'] = $target?->uuid;
                $g['achievement_percent'] = $target ? bcdiv(bcmul((string) $g['net_sales_minor'], '100', 2), (string) $target->amount_minor, 2) : null;
                foreach (['sales_minor', 'returns_minor', 'net_sales_minor', 'credit_minor', 'collected_minor', 'balance_minor', 'overdue_balance_minor'] as $field) {
                    $g[$field] = (string) $g[$field];
                }$rows[] = $g;
            }
            usort($rows, fn ($a, $b) => strcmp($a['employee_name'], $b['employee_name']) ?: strcmp($a['currency'], $b['currency']));
            $latest = ImportBatch::query()->where('status', 'imported')->whereIn('kind', ['invoices', 'receipts', 'returns'])->max('committed_at');

            return ['rows' => $rows, 'evidence' => $evidence, 'quality' => ['invoice_count' => $invoices->count(), 'recorded_receipt_count' => $receiptCount, 'latest_financial_import_at' => $latest, 'coverage_confirmed' => false, 'warnings' => ['هذه نتائج الملفات المستوردة فقط؛ اكتمال الفواتير والتحصيلات لم يُؤكّد.', 'الرصيد والمتأخر تقدير من السجلات المتاحة، وليس إثباتاً نهائياً للدين.', 'المرتجعات المسجلة تُخصم من الفواتير المختارة حتى تاريخ القياس؛ لا تشمل الإشعارات الأخرى والافتتاحيات والتحويل بين العملات.', 'الهدف يظهر فقط إذا طابقت فترته فترة التقرير بالضبط؛ هذا التقرير لا يعتمد مكافأة.', 'الرصيد الدائن بعد المرتجعات يظهر منفصلاً؛ لا ينفذ النظام رد أموال ولا يخصمه من فواتير أخرى.']]];
        });
    }
}
