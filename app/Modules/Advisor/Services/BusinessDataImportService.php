<?php

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\CustomerArea;
use App\Modules\Advisor\Models\InvoiceLine;
use App\Modules\Advisor\Models\Product;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BusinessDataImportService
{
    public static function supports(string $kind): bool
    {
        return in_array($kind, ['products', 'areas', 'customer_locations', 'invoice_lines'], true);
    }

    public static function available(): bool
    {
        return Schema::hasTable('advisor_products') && Schema::hasTable('advisor_areas') && Schema::hasTable('advisor_customer_areas') && Schema::hasTable('advisor_invoice_lines') && Schema::hasColumn('advisor_sales_invoices', 'area_id');
    }

    public function amount(string $value, string $currency): ?int
    {
        if (preg_match('/^0(?:\.0{1,'.($currency === 'IQD' ? 3 : 2).'})?$/D', $value)) {
            return 0;
        }

        return app(CsvImportService::class)->minor($value, $currency);
    }

    public function validateRows(array $rows, string $kind, string $source): array
    {
        abort_unless(self::available(), 503, 'Business catalogs require the pending schema upgrade.');
        $errors = [];
        $seen = [];
        $totals = [];
        $code = ['required', 'string', 'max:100', 'regex:/^[\pL\pN][\pL\pN._\/\-]*$/u'];
        foreach ($rows as $row) {
            $d = $row['values'];
            $issues = [];
            $rules = match ($kind) {
                'products' => ['product_code' => [...$code, 'max:60'], 'name' => ['required', 'string', 'max:255'], 'base_unit' => ['required', 'string', 'max:60']],
                'areas' => ['area_code' => [...$code, 'max:60'], 'province_name' => ['required', 'string', 'max:120'], 'city_name' => ['required', 'string', 'max:120']],
                'customer_locations' => ['customer_number' => [...$code, 'max:60'], 'area_code' => [...$code, 'max:60']],
                'invoice_lines' => ['external_id' => $code, 'invoice_external_id' => $code, 'product_code' => [...$code, 'max:60'], 'quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?$/D'], 'net_amount' => ['required', 'string']],
            };
            $v = Validator::make($d, $rules);
            foreach (array_keys($v->errors()->toArray()) as $field) {
                $issues[$field] = 'قيمة مفقودة أو غير صالحة؛ راجع القالب.';
            }
            $id = $d[match ($kind) {
                'products' => 'product_code', 'areas' => 'area_code', 'customer_locations' => 'customer_number', default => 'external_id'
            }];
            if (isset($seen[$id])) {
                $issues['identifier'] = 'معرّف مكرر داخل الملف.';
            }
            $seen[$id] = true;
            if ($kind === 'products' || $kind === 'areas') {
                $model = $kind === 'products' ? Product::class : Area::class;
                if ($model::query()->where('code', $id)->exists()) {
                    $issues['identifier'] = 'الرمز موجود في شركتك؛ الاستيراد يضيف فقط.';
                }
            } elseif ($kind === 'customer_locations') {
                $customer = Customer::query()->where('customer_number', $id)->first();
                if (! $customer) {
                    $issues['customer_number'] = 'العميل غير موجود في شركتك.';
                } elseif (CustomerArea::query()->where('customer_id', $customer->id)->exists()) {
                    $issues['customer_number'] = 'للعميل منطقة مسجلة؛ لا يستبدل الاستيراد الموقع.';
                }
                if (! Area::query()->where('code', $d['area_code'])->exists()) {
                    $issues['area_code'] = 'المنطقة غير موجودة في شركتك.';
                }
            } else {
                if (InvoiceLine::query()->where('source_system', $source)->where('external_id', $id)->exists()) {
                    $issues['external_id'] = 'سطر الفاتورة موجود لهذا المصدر.';
                }
                if (! Product::query()->where('code', $d['product_code'])->exists()) {
                    $issues['product_code'] = 'المنتج غير موجود في شركتك.';
                }
                if (! $v->errors()->has('quantity') && bccomp($d['quantity'], '0', 3) <= 0) {
                    $issues['quantity'] = 'الكمية يجب أن تكون موجبة وبوحدة المنتج الأساسية.';
                }
                $invoice = SalesInvoice::query()->where('source_system', $source)->where('external_id', $d['invoice_external_id'])->first();
                if (! $invoice) {
                    $issues['invoice_external_id'] = 'الفاتورة غير موجودة في شركتك ولهذا المصدر.';
                } else {
                    $amount = $this->amount($d['net_amount'], $invoice->currency);
                    if ($amount === null) {
                        $issues['net_amount'] = 'مبلغ غير سالب مطلوب بدقة عملة الفاتورة، دون فواصل آلاف.';
                    } else {
                        $totals[$invoice->id] ??= (string) InvoiceLine::query()->where('invoice_id', $invoice->id)->sum('net_amount_minor');
                        $totals[$invoice->id] = bcadd($totals[$invoice->id], (string) $amount, 0);
                        if (bccomp($totals[$invoice->id], (string) $invoice->net_amount_minor, 0) > 0) {
                            $issues['net_amount'] = 'مجموع صافي السطور يتجاوز مبلغ الفاتورة؛ راجع توزيع الخصم والتكاليف.';
                        }
                    }
                }
            }
            foreach ($issues as $field => $message) {
                $errors[] = ['line' => $row['line'], 'field' => $field, 'message' => $message];
            }
        }

        return $errors;
    }

    public function write(array $rows, string $kind, string $source, int $batch): void
    {
        abort_unless(self::available(), 503, 'Business catalogs require the pending schema upgrade.');
        foreach ($rows as $row) {
            $d = $row['values'];
            $base = ['uuid' => (string) Str::uuid(), 'batch_id' => $batch];
            if ($kind === 'products') {
                Product::query()->create([...$base, 'code' => $d['product_code'], 'name' => $d['name'], 'base_unit' => $d['base_unit']]);
            } elseif ($kind === 'areas') {
                Area::query()->create([...$base, 'code' => $d['area_code'], 'province_name' => $d['province_name'], 'city_name' => $d['city_name'], 'country_code' => 'IQ']);
            } elseif ($kind === 'customer_locations') {
                CustomerArea::query()->create([...$base, 'customer_id' => Customer::query()->where('customer_number', $d['customer_number'])->firstOrFail()->id, 'area_id' => Area::query()->where('code', $d['area_code'])->firstOrFail()->id]);
            } else {
                $invoice = SalesInvoice::query()->where('source_system', $source)->where('external_id', $d['invoice_external_id'])->firstOrFail();
                $product = Product::query()->where('code', $d['product_code'])->firstOrFail();
                InvoiceLine::query()->create([...$base, 'source_system' => $source, 'external_id' => $d['external_id'], 'invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => $d['quantity'], 'base_unit' => $product->base_unit, 'net_amount_minor' => $this->amount($d['net_amount'], $invoice->currency)]);
            }
        }
    }
}
