<?php

namespace App\Modules\Advisor\Services;

use App\Core\Tenancy\TenantContext;
use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\InventoryObservation;
use App\Modules\Advisor\Models\InvoiceLine;
use App\Modules\Advisor\Models\Product;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReturn;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InventoryImportService
{
    public const HEADERS = [
        'stock_snapshots' => ['external_id', 'product_code', 'area_code', 'snapshot_date', 'quantity'],
        'product_costs' => ['external_id', 'product_code', 'effective_date', 'currency', 'unit_cost'],
        'stockouts' => ['external_id', 'product_code', 'area_code', 'from_date', 'to_date'],
        'return_lines' => ['external_id', 'return_external_id', 'invoice_line_external_id', 'quantity', 'amount']];

    public static function supports(string $kind): bool
    {
        return isset(self::HEADERS[$kind]);
    }

    public static function available(): bool
    {
        return BusinessDataImportService::available() && Schema::hasTable('advisor_inventory_observations');
    }

    public function validateRows(array $rows, string $kind, string $source): array
    {
        abort_unless(self::available(), 503, 'Inventory inputs require the pending schema upgrade.');
        $errors = [];
        $seen = [];
        $quantities = [];
        $amounts = [];
        $code = ['required', 'string', 'max:100', 'regex:/^[\pL\pN][\pL\pN._\/\-]*$/u'];
        $qty = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?$/D'];
        $today = now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString();
        foreach ($rows as $row) {
            $d = $row['values'];
            $rules = ['external_id' => $code];
            if ($kind === 'return_lines') {
                $rules += ['return_external_id' => $code, 'invoice_line_external_id' => $code, 'quantity' => $qty, 'amount' => ['required', 'string']];
            } else {
                $rules['product_code'] = $code;
                $rules += match ($kind) {
                    'stock_snapshots' => ['area_code' => $code, 'snapshot_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'quantity' => $qty],'product_costs' => ['effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'currency' => ['required', 'in:USD,IQD'], 'unit_cost' => ['required', 'string']],default => ['area_code' => $code, 'from_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:'.$today]]
                };
            }
            $v = Validator::make($d, $rules);
            $issues = [];
            foreach (array_keys($v->errors()->toArray()) as $f) {
                $issues[$f] = 'قيمة غير صالحة؛ راجع القالب ودقة الوحدة والتاريخ.';
            }
            if (isset($seen[$d['external_id']]) || InventoryObservation::query()->where('source_system', $source)->where('kind', $kind)->where('external_id', $d['external_id'])->exists()) {
                $issues['external_id'] = 'معرّف مكرر؛ الاستيراد يضيف فقط.';
            }$seen[$d['external_id']] = true;
            if ($kind !== 'return_lines') {
                $product = Product::query()->where('code', $d['product_code'])->first();
                if (! $product) {
                    $issues['product_code'] = 'المنتج غير موجود في شركتك.';
                }
                if (isset($d['area_code']) && ! Area::query()->where('code', $d['area_code'])->exists()) {
                    $issues['area_code'] = 'المنطقة غير موجودة في شركتك.';
                }
                if ($kind === 'product_costs' && ! $v->fails() && app(BusinessDataImportService::class)->amount($d['unit_cost'], $d['currency']) === null) {
                    $issues['unit_cost'] = 'تكلفة غير سالبة مطلوبة بدقة العملة.';
                }
            } else {
                $line = InvoiceLine::query()->where('source_system', $source)->where('external_id', $d['invoice_line_external_id'])->first();
                $return = SalesReturn::query()->where('source_system', $source)->where('external_id', $d['return_external_id'])->first();
                if (! $line) {
                    $issues['invoice_line_external_id'] = 'سطر الفاتورة غير موجود لهذا المصدر والشركة.';
                }if (! $return) {
                    $issues['return_external_id'] = 'سجل المرتجع غير موجود لهذا المصدر والشركة.';
                }
                if ($line && $return) {
                    if ($line->invoice_id !== $return->invoice_id) {
                        $issues['invoice_line_external_id'] = 'المرتجع وسطر الفاتورة يجب أن ينتميا للفاتورة نفسها.';
                    }
                    if (! $v->errors()->has('quantity')) {
                        $quantities[$line->id] ??= (string) InventoryObservation::query()->where('kind', 'return_lines')->where('invoice_line_id', $line->id)->sum('quantity');
                        $quantities[$line->id] = bcadd($quantities[$line->id], $d['quantity'], 3);
                        if (bccomp($d['quantity'], '0', 3) <= 0 || bccomp($quantities[$line->id], $line->quantity, 3) > 0) {
                            $issues['quantity'] = 'كمية موجبة مطلوبة دون تجاوز إجمالي الكمية المباعة.';
                        }
                    }
                    $amount = app(BusinessDataImportService::class)->amount($d['amount'], $return->currency);
                    if ($amount === null) {
                        $issues['amount'] = 'مبلغ غير سالب مطلوب بدقة عملة المرتجع.';
                    } else {
                        $amounts[$return->id] ??= (string) InventoryObservation::query()->where('return_id', $return->id)->sum('amount_minor');
                        $amounts[$return->id] = bcadd($amounts[$return->id], (string) $amount, 0);
                        if (bccomp($amounts[$return->id], (string) $return->amount_minor, 0) > 0) {
                            $issues['amount'] = 'مجموع سطور المرتجع يتجاوز مبلغ سجل المرتجع.';
                        }
                    }
                }
            }
            foreach ($issues as $f => $message) {
                $errors[] = ['line' => $row['line'], 'field' => $f, 'message' => $message];
            }
        }

return $errors;
    }

    public function write(array $rows, string $kind, string $source, int $batch): void
    {
        abort_unless(self::available(), 503, 'Inventory inputs require the pending schema upgrade.');
        foreach ($rows as $row) {
            $d = $row['values'];
            $base = ['uuid' => Str::uuid(), 'kind' => $kind, 'source_system' => $source, 'external_id' => $d['external_id'], 'batch_id' => $batch];
            if ($kind === 'return_lines') {
                $line = InvoiceLine::query()->where('source_system', $source)->where('external_id', $d['invoice_line_external_id'])->firstOrFail();
                $ret = SalesReturn::query()->where('source_system', $source)->where('external_id', $d['return_external_id'])->firstOrFail();
                $invoice = SalesInvoice::query()->findOrFail($line->invoice_id);
                $base += ['product_id' => $line->product_id, 'area_id' => $invoice->area_id, 'invoice_line_id' => $line->id, 'return_id' => $ret->id, 'observed_on' => $ret->return_date->toDateString(), 'quantity' => $d['quantity'], 'currency' => $ret->currency, 'amount_minor' => app(BusinessDataImportService::class)->amount($d['amount'], $ret->currency)];
            } else {
                $base['product_id'] = Product::query()->where('code', $d['product_code'])->firstOrFail()->id;
                if (isset($d['area_code'])) {
                    $base['area_id'] = Area::query()->where('code', $d['area_code'])->firstOrFail()->id;
                }
                $base += match ($kind) {
                    'stock_snapshots' => ['observed_on' => $d['snapshot_date'], 'quantity' => $d['quantity']],'product_costs' => ['observed_on' => $d['effective_date'], 'currency' => $d['currency'], 'amount_minor' => app(BusinessDataImportService::class)->amount($d['unit_cost'], $d['currency'])],default => ['observed_on' => $d['from_date'], 'end_date' => $d['to_date']]
                };
            }InventoryObservation::query()->create($base);
        }
    }
}
