<?php

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Models\BonusPolicy;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Models\SalesReturn;
use App\Modules\Advisor\Models\SalesTarget;
use App\Modules\HR\Models\CompensationRecord;
use App\Modules\HR\Models\Employee;

class BonusReviewService
{
    // Caller holds the tenant and employee locks. No model-generated financial decisions.
    public function evaluate(Employee $employee, BonusPolicy $policy, array $d): array
    {
        $filters = ['from' => $d['period_from'], 'to' => $d['period_to'], 'as_of' => $d['as_of'], 'currency' => $policy->currency, 'employee_uuid' => $employee->uuid];
        $dataset = app(SalesMetricsService::class)->dataset($filters);
        $metrics = $dataset['rows'][0] ?? ['sales_minor' => '0', 'returns_minor' => '0', 'net_sales_minor' => '0', 'collected_minor' => '0', 'credit_minor' => '0', 'overdue_balance_minor' => '0', 'collection_percent' => null];
        $target = SalesTarget::query()->where('employee_id', $employee->id)->where('currency', $policy->currency)->whereDate('period_from', $d['period_from'])->whereDate('period_to', $d['period_to'])->first();
        $comp = CompensationRecord::query()->where('employee_id', $employee->id)->whereDate('effective_from', '<=', $d['period_from'])->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $d['period_to']))->get();
        $reasons = [];
        $add = function (string $code, string $level, string $message) use (&$reasons) {
            $reasons[] = compact('code', 'level', 'message');
        };
        $add('coverage_unconfirmed', 'missing', 'اكتمال بيانات الشركة لم يُؤكّد. طابق الفواتير والتحصيلات والمرتجعات مع المصدر قبل القرار.');
        if ($employee->employment_status !== 'active' || $employee->trashed()) {
            $add('employee_inactive', 'objection', 'الموظف غير نشط؛ راجع أهلية المكافأة.');
        }
        if (! $target) {
            $add('target_missing', 'missing', 'لا يوجد هدف بنفس الموظف والعملة وبنفس فترة الطلب بالضبط.');
        } else {
            $actual = bcmul($metrics['net_sales_minor'], '100', 2);
            $threshold = bcmul((string) $target->amount_minor, $policy->rules['minimum_sales_percent'], 2);
            $met = bccomp($actual, $threshold, 2) >= 0;
            $percent = bcdiv($actual, (string) $target->amount_minor, 2);
            $add('sales_threshold', $met ? 'met' : 'objection', 'تحقيق هدف صافي المبيعات '.$percent.'%؛ السياسة تتطلب '.$policy->rules['minimum_sales_percent'].'%.');
        }
        if (bccomp($metrics['net_sales_minor'], '0', 0) <= 0) {
            $add('sales_missing', 'missing', 'لا توجد مبيعات صافية موجبة في الفواتير المختارة؛ لا يمكن حساب نسبة التحصيل.');
        } else {
            $met = bccomp(bcmul($metrics['collected_minor'], '100', 2), bcmul($metrics['net_sales_minor'], $policy->rules['minimum_collection_percent'], 2), 2) >= 0;
            $add('collection_threshold', $met ? 'met' : 'objection', 'نسبة التحصيل من صافي المبيعات '.$metrics['collection_percent'].'%؛ السياسة تتطلب '.$policy->rules['minimum_collection_percent'].'%.');
        }
        if (bccomp($metrics['credit_minor'], '0') > 0) {
            $add('credit_requires_reconciliation', 'missing', 'يوجد رصيد دائن بعد المرتجعات؛ راجع التسوية ورد الأموال قبل اعتماد المكافأة.');
        }
        $annualMinor = null;
        $salary = $comp->count() === 1 && $comp->first()->currency_code === $policy->currency ? $comp->first() : null;
        if ($salary) {
            $annualMinor = app(CsvImportService::class)->minor((string) $salary->annual_base_encrypted, $policy->currency);
        }
        $cap = null;
        if ($annualMinor === null) {
            $add('compensation_missing', 'missing', 'يلزم تعويض سنوي واحد صالح بنفس العملة يغطي كامل فترة الطلب لحساب سقف المكافأة.');
        } else {
            // Cap is a policy-defined percentage of one month's annual base / 12, rounded down to the minor unit.
            $cap = bcdiv(bcmul((string) $annualMinor, $policy->rules['maximum_monthly_base_percent'], 2), '1200', 0);
            $met = bccomp((string) $d['amount_minor'], $cap, 0) <= 0;
            $add('bonus_cap', $met ? 'met' : 'objection', 'المبلغ المطلوب '.$this->money($d['amount_minor'], $policy->currency).'؛ السقف '.$this->money($cap, $policy->currency).' ('.$policy->rules['maximum_monthly_base_percent'].'% من الأساس السنوي ÷ 12).');
        }
        $ids = array_column($dataset['evidence'], 'uuid');
        $transactions = [];
        foreach ([SalesReceipt::class => 'receipt_date', SalesReturn::class => 'return_date'] as $class => $date) {
            $records = $class::query()->whereIn('invoice_id', SalesInvoice::query()->whereIn('uuid', $ids)->select('id'))->whereDate($date, '<=', $d['as_of'])->orderBy('id')->get();
            foreach ($records as $record) {
                $transactions[] = ['kind' => $date === 'receipt_date' ? 'receipt' : 'return', 'uuid' => $record->uuid, 'invoice_id' => $record->invoice_id, 'source_system' => $record->source_system, 'external_id' => $record->external_id, 'date' => $record->$date->toDateString(), 'amount_minor' => (string) $record->amount_minor];
            }
        }
        $snapshot = ['engine_version' => 1, 'employee' => ['uuid' => $employee->uuid, 'name' => $employee->first_name.' '.$employee->last_name, 'status' => $employee->employment_status, 'deleted_at' => $employee->deleted_at?->toISOString()], 'filters' => $filters,
            'policy' => ['uuid' => $policy->uuid, 'name' => $policy->name, 'version' => $policy->version, 'mode' => $policy->mode, 'rules' => $policy->rules],
            'requested_minor' => (string) $d['amount_minor'], 'metrics' => $metrics, 'target' => $target ? ['uuid' => $target->uuid, 'source_system' => $target->source_system, 'external_id' => $target->external_id, 'amount_minor' => (string) $target->amount_minor] : null,
            'compensation' => $salary ? ['record_id' => $salary->id, 'annual_base' => $salary->annual_base_encrypted, 'currency' => $salary->currency_code, 'effective_from' => $salary->effective_from->toDateString(), 'effective_to' => $salary->effective_to?->toDateString()] : null,
            'cap_minor' => $cap, 'reasons' => $reasons, 'coverage_confirmed' => false, 'evidence' => $dataset['evidence'], 'transactions' => $transactions];
        $snapshot['result'] = collect($reasons)->contains('level', 'objection') ? 'objection' : 'needs_review';
        $snapshot['next_action'] = 'أكمل أو طابق البيانات، أو اطلب قرار مدير مستقل مع سبب موثق. أي تجاوز في النمط الإلزامي يتطلب صلاحية مستقلة؛ لا ينفذ هذا الطلب صرفاً.';

        return ['snapshot' => $snapshot, 'fingerprint' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    private function money(string|int $minor, string $currency): string
    {
        return bcdiv((string) $minor, $currency === 'IQD' ? '1000' : '100', $currency === 'IQD' ? 3 : 2).' '.$currency;
    }
}
