<?php

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReceipt;
use App\Modules\Advisor\Models\SalesReturn;
use App\Modules\Advisor\Models\SalesTarget;
use App\Modules\HR\Models\CompensationRecord;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CsvImportService
{
    public const REQUIRED = ['employees' => ['employee_number', 'first_name', 'last_name'], 'customers' => ['customer_number', 'name'], 'invoices' => ['external_id', 'invoice_date', 'due_date', 'customer_number', 'employee_number', 'currency', 'net_amount'], 'receipts' => ['external_id', 'invoice_external_id', 'receipt_date', 'currency', 'amount'], 'returns' => ['external_id', 'invoice_external_id', 'return_date', 'currency', 'amount'], 'targets' => ['external_id', 'employee_number', 'period_from', 'period_to', 'currency', 'amount'], 'compensation' => ['employee_number', 'annual_base', 'currency', 'effective_from']];

    public const HEADERS = [
        'employees' => ['employee_number', 'first_name', 'last_name', 'work_email', 'hired_at'],
        'customers' => ['customer_number', 'name', 'email'],
        'invoices' => ['external_id', 'invoice_date', 'due_date', 'customer_number', 'employee_number', 'currency', 'net_amount'],
        'receipts' => ['external_id', 'invoice_external_id', 'receipt_date', 'currency', 'amount'],
        'returns' => ['external_id', 'invoice_external_id', 'return_date', 'currency', 'amount'],
        'targets' => ['external_id', 'employee_number', 'period_from', 'period_to', 'currency', 'amount'],
        'compensation' => ['employee_number', 'annual_base', 'currency', 'effective_from', 'effective_to'],
    ];

    public function parse(string $path, string $kind): array
    {
        $stream = fopen($path, 'rb');
        $rows = [];
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if ($header) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            if ($header !== self::HEADERS[$kind]) {
                throw ValidationException::withMessages(['file' => 'استخدم أعمدة القالب بالترتيب نفسه وملف CSV UTF-8.']);
            }
            $line = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue;
                }
                if (count($rows) >= 1000) {
                    throw ValidationException::withMessages(['file' => 'الحد الأقصى 1000 سجل لكل دفعة.']);
                }
                if (count($cells) !== count($header)) {
                    throw ValidationException::withMessages(['file' => "عدد الأعمدة غير صحيح في السجل {$line}."]);
                }
                foreach ($cells as &$cell) {
                    $cell = trim((string) $cell);
                    if (! mb_check_encoding($cell, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $cell)) {
                        throw ValidationException::withMessages(['file' => "ترميز أو محارف غير مقبولة في السجل {$line}."]);
                    }
                }unset($cell);
                $rows[] = ['line' => $line, 'values' => array_combine($header, $cells)];
            }
        } finally {
            fclose($stream);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'الملف لا يحتوي على سجلات.']);
        }

        return $rows;
    }

    public function minor(string $amount, string $currency): ?int
    {
        $digits = $currency === 'IQD' ? 3 : 2;
        if (! preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,'.$digits.'}))?$/D', $amount, $m)) {
            return null;
        }
        $minor = (int) $m[1] * (10 ** $digits) + (int) str_pad($m[2] ?? '', $digits, '0');

        return $minor > 0 && $minor <= 1000000000000 ? $minor : null;
    }

    public function validateRows(array $rows, string $kind, string $source): array
    {
        $errors = [];
        $seen = [];
        $emails = [];
        $receiptTotals = [];
        $periods = [];
        $code = ['required', 'string', 'max:100', 'regex:/^[\pL\pN][\pL\pN._\/\-]*$/u'];
        foreach ($rows as $row) {
            $d = $row['values'];
            $problem = [];
            $rules = match ($kind) {
                'employees' => ['employee_number' => [...$code, 'max:60'], 'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'], 'work_email' => ['nullable', 'email', 'max:255'], 'hired_at' => ['nullable', 'date_format:Y-m-d']],
                'customers' => ['customer_number' => [...$code, 'max:60'], 'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255']],
                'invoices' => ['external_id' => $code, 'invoice_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'], 'customer_number' => [...$code, 'max:60'], 'employee_number' => [...$code, 'max:60'], 'currency' => ['required', 'in:USD,IQD'], 'net_amount' => ['required', 'string']],
                'returns' => ['external_id' => $code, 'invoice_external_id' => $code, 'return_date' => ['required', 'date_format:Y-m-d'], 'currency' => ['required', 'in:USD,IQD'], 'amount' => ['required', 'string']],
                'targets' => ['external_id' => $code, 'employee_number' => [...$code, 'max:60'], 'period_from' => ['required', 'date_format:Y-m-d'], 'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'], 'currency' => ['required', 'in:USD,IQD'], 'amount' => ['required', 'string']],
                'compensation' => ['employee_number' => [...$code, 'max:60'], 'annual_base' => ['required', 'string'], 'currency' => ['required', 'in:USD,IQD'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from']],
                'receipts' => ['external_id' => $code, 'invoice_external_id' => $code, 'receipt_date' => ['required', 'date_format:Y-m-d'], 'currency' => ['required', 'in:USD,IQD'], 'amount' => ['required', 'string']],
            };
            $v = Validator::make(array_map(fn ($x) => $x === '' ? null : $x, $d), $rules);
            foreach (array_keys($v->errors()->toArray()) as $field) {
                $problem[$field] = 'قيمة مفقودة أو غير صالحة؛ راجع القالب.';
            }
            $identifier = $d[match ($kind) {
                'employees', 'compensation' => 'employee_number','customers' => 'customer_number',default => 'external_id'
            }];
            $unique = $kind === 'compensation' ? $identifier.':'.$d['effective_from'] : $identifier;
            if (isset($seen[$unique])) {
                $problem['identifier'] = 'معرّف مكرر داخل الملف.';
            }$seen[$unique] = true;
            if ($kind === 'employees') {
                if (Employee::withTrashed()->where('employee_number', $identifier)->exists()) {
                    $problem['employee_number'] = 'الرقم موجود؛ الاستيراد يضيف فقط ولا يستبدل السجلات.';
                }
                if ($d['work_email'] !== '') {
                    $email = mb_strtolower($d['work_email']);
                    if (isset($emails[$email]) || Employee::withTrashed()->whereRaw('LOWER(work_email) = ?', [$email])->exists()) {
                        $problem['work_email'] = 'بريد وظيفي مكرر.';
                    }$emails[$email] = true;
                }
            } elseif ($kind === 'customers') {
                if (Customer::withTrashed()->where('customer_number', $identifier)->exists()) {
                    $problem['customer_number'] = 'رقم العميل موجود؛ الاستيراد يضيف فقط.';
                }
            } elseif ($kind === 'invoices') {
                if (SalesInvoice::query()->where('source_system', $source)->where('external_id', $identifier)->exists()) {
                    $problem['external_id'] = 'الفاتورة موجودة لهذا المصدر.';
                }
                if (! Customer::query()->where('customer_number', $d['customer_number'])->exists()) {
                    $problem['customer_number'] = 'لم يُعثر على العميل في شركتك؛ استورده أولاً.';
                }
                if (! Employee::query()->where('employee_number', $d['employee_number'])->exists()) {
                    $problem['employee_number'] = 'لم يُعثر على الموظف في شركتك؛ استورده أولاً.';
                }
                if ($this->minor($d['net_amount'], $d['currency']) === null) {
                    $problem['net_amount'] = 'مبلغ موجب مطلوب، حتى منزلتين للدولار أو ثلاث للدينار، دون فواصل آلاف.';
                }
            } elseif (in_array($kind, ['targets', 'compensation'], true)) {
                $employee = Employee::query()->where('employee_number', $d['employee_number'])->first();
                $start = $d[$kind === 'targets' ? 'period_from' : 'effective_from'];
                $end = $d[$kind === 'targets' ? 'period_to' : 'effective_to'] ?: '9999-12-31';
                $amountField = $kind === 'targets' ? 'amount' : 'annual_base';
                if (! $employee) {
                    $problem['employee_number'] = 'الموظف غير موجود في شركتك.';
                }
                if ($this->minor($d[$amountField], $d['currency']) === null) {
                    $problem[$amountField] = 'مبلغ موجب مطلوب بالدقة المحددة للعملة.';
                }
                if ($kind === 'targets' && SalesTarget::query()->where('source_system', $source)->where('external_id', $identifier)->exists()) {
                    $problem['external_id'] = 'الهدف موجود لهذا المصدر.';
                }
                if ($employee && ! $v->fails()) {
                    if ($kind === 'targets') {
                        $overlap = SalesTarget::query()->where('employee_id', $employee->id)->where('currency', $d['currency'])->whereDate('period_from', '<=', $end)->whereDate('period_to', '>=', $start)->exists();
                    } else {
                        $overlap = CompensationRecord::query()->where('employee_id', $employee->id)->whereDate('effective_from', '<=', $end)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))->exists();
                    }
                    $periodKey = $employee->id.':'.($kind === 'targets' ? $d['currency'] : 'salary');
                    foreach ($periods[$periodKey] ?? [] as [$a,$b]) {
                        if ($a <= $end && $b >= $start) {
                            $overlap = true;
                        }
                    }
                    $periods[$periodKey][] = [$start, $end];
                    if ($overlap) {
                        $problem['period'] = 'فترة متداخلة؛ الاستيراد لا يغلق أو يستبدل السجلات السابقة.';
                    }
                }
            } else {
                $model = $kind === 'returns' ? SalesReturn::class : SalesReceipt::class;
                $dateField = $kind === 'returns' ? 'return_date' : 'receipt_date';
                if ($model::query()->where('source_system', $source)->where('external_id', $identifier)->exists()) {
                    $problem['external_id'] = 'الحركة موجودة لهذا المصدر.';
                }
                $invoice = SalesInvoice::query()->where('source_system', $source)->where('external_id', $d['invoice_external_id'])->first();
                $amount = $this->minor($d['amount'], $d['currency']);
                if (! $invoice) {
                    $problem['invoice_external_id'] = 'الفاتورة غير موجودة في شركتك ولهذا المصدر.';
                } else {
                    if ($invoice->currency !== $d['currency']) {
                        $problem['currency'] = 'عملة الحركة يجب أن تطابق الفاتورة.';
                    }
                    if (! $v->errors()->has($dateField) && $d[$dateField] < $invoice->invoice_date->toDateString()) {
                        $problem[$dateField] = 'التاريخ يسبق الفاتورة.';
                    }
                    $receiptTotals[$invoice->id] ??= (int) $model::query()->where('invoice_id', $invoice->id)->sum('amount_minor');
                    if ($amount !== null) {
                        $receiptTotals[$invoice->id] += $amount;
                        if ($receiptTotals[$invoice->id] > $invoice->net_amount_minor) {
                            $problem['amount'] = 'مجموع الحركات لهذا النوع يتجاوز قيمة الفاتورة.';
                        }
                    }
                }
                if ($amount === null) {
                    $problem['amount'] = 'مبلغ موجب مطلوب بالدقة المحددة للعملة، دون فواصل آلاف.';
                }
            }
            foreach ($problem as $field => $message) {
                $errors[] = ['line' => $row['line'], 'field' => $field, 'message' => $message];
            }
        }

        return $errors;
    }

    public function write(array $rows, string $kind, string $source, int $batch): void
    {
        foreach ($rows as $row) {
            $d = $row['values'];
            $base = ['uuid' => (string) Str::uuid()];
            if ($kind === 'employees') {
                Employee::query()->create([...$base, 'employee_number' => $d['employee_number'], 'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'work_email' => $d['work_email'] !== '' ? mb_strtolower($d['work_email']) : null, 'hired_at' => $d['hired_at'] ?: null]);
            } elseif ($kind === 'customers') {
                Customer::query()->create([...$base, 'customer_number' => $d['customer_number'], 'name' => $d['name'], 'email' => $d['email'] ?: null, 'type' => 'organization', 'status' => 'active']);
            } elseif ($kind === 'invoices') {
                SalesInvoice::query()->create([...$base, 'batch_id' => $batch, 'source_system' => $source, 'external_id' => $d['external_id'], 'invoice_date' => $d['invoice_date'], 'due_date' => $d['due_date'], 'customer_id' => Customer::query()->where('customer_number', $d['customer_number'])->firstOrFail()->id, 'employee_id' => Employee::query()->where('employee_number', $d['employee_number'])->firstOrFail()->id, 'currency' => $d['currency'], 'net_amount_minor' => $this->minor($d['net_amount'], $d['currency'])]);
            } elseif ($kind === 'targets') {
                SalesTarget::query()->create([...$base, 'batch_id' => $batch, 'source_system' => $source, 'external_id' => $d['external_id'], 'employee_id' => Employee::query()->where('employee_number', $d['employee_number'])->firstOrFail()->id, 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'currency' => $d['currency'], 'amount_minor' => $this->minor($d['amount'], $d['currency'])]);
            } elseif ($kind === 'compensation') {
                // Serialize with manual compensation writes too. Recheck after taking the employee lock.
                $employee = Employee::query()->where('employee_number', $d['employee_number'])->lockForUpdate()->firstOrFail();
                if ($this->validateRows([$row], $kind, $source)) {
                    throw ValidationException::withMessages(['file' => 'تغيرت التعويضات؛ أعد المعاينة.']);
                }
                CompensationRecord::query()->create(['employee_id' => $employee->id, 'annual_base_encrypted' => $d['annual_base'], 'currency_code' => $d['currency'], 'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'] ?: null, 'approved_by' => auth()->id()]);
            } else {
                $model = $kind === 'returns' ? SalesReturn::class : SalesReceipt::class;
                $dateField = $kind === 'returns' ? 'return_date' : 'receipt_date';
                $model::query()->create([...$base, 'batch_id' => $batch, 'source_system' => $source, 'external_id' => $d['external_id'], 'invoice_id' => SalesInvoice::query()->where('source_system', $source)->where('external_id', $d['invoice_external_id'])->firstOrFail()->id, $dateField => $d[$dateField], 'currency' => $d['currency'], 'amount_minor' => $this->minor($d['amount'], $d['currency'])]);
            }
        }
    }
}
