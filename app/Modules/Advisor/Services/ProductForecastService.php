<?php

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\InventoryObservation;
use App\Modules\Advisor\Models\InvoiceLine;
use App\Modules\Advisor\Models\Product;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SalesReturn;
use App\Modules\Advisor\Models\SourceAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class ProductForecastService
{
    public function analyze(array $d): array
    {
        abort_unless(InventoryImportService::available(), 503, 'Forecast inputs require the pending schema upgrade.');
        $authority = Schema::hasTable('advisor_source_authorities') ? SourceAuthority::query()->where('kind', 'invoices')->latest('version')->first() : null;
        abort_if($authority && $authority->source_system !== $d['source_system'], 422, 'مصدر التوقع يخالف مصدر الحقيقة الحالي للفواتير.');
        $product = Product::query()->where('uuid', $d['product_uuid'])->firstOrFail();
        $area = Area::query()->where('uuid', $d['area_uuid'])->firstOrFail();
        $from = CarbonImmutable::parse($d['from']);
        $to = CarbonImmutable::parse($d['to']);
        $days = (int) $from->diffInDays($to) + 1;
        abort_unless($from->dayOfWeekIso === 1 && $to->dayOfWeekIso === 7 && $days % 7 === 0 && $days >= 84 && $days <= 728, 422, 'اختر 12 إلى 104 أسابيع مكتملة من الاثنين إلى الأحد.');
        $invoices = SalesInvoice::query()->where('source_system', $d['source_system'])->where('area_id', $area->id)->whereBetween('invoice_date', [$d['from'], $d['to']]);
        $count = (clone $invoices)->count();
        abort_if($count > 10000, 422, 'قسّم نطاق التوقع؛ الحد 10000 فاتورة.');
        $cohort = $invoices->orderBy('id')->get();
        $lines = InvoiceLine::query()->whereIn('invoice_id', $cohort->pluck('id'));
        abort_if((clone $lines)->count() > 20000, 422, 'قسّم نطاق التوقع؛ الحد 20000 سطر.');
        $allLines = $lines->orderBy('id')->get();
        $returns = SalesReturn::query()->whereIn('invoice_id', $cohort->pluck('id'))->where('return_date', '<=', $d['to'])->orderBy('id')->get();
        $returnLines = InventoryObservation::query()->where('kind', 'return_lines')->whereIn('return_id', $returns->pluck('id'))->orderBy('id')->get();
        $linesByInvoice = $allLines->groupBy('invoice_id');
        $returnsByHeader = $returnLines->groupBy('return_id');
        $returnsByLine = $returnLines->groupBy('invoice_line_id');
        $invoicesById = $cohort->keyBy('id');
        $sumMoney = fn ($rows, $field) => $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->$field, 0), '0');
        $missing = 0;
        foreach ($cohort as $i) {
            $rows = $linesByInvoice->get($i->id, collect());
            if ($rows->isEmpty() || $sumMoney($rows, 'net_amount_minor') !== (string) $i->net_amount_minor) {
                $missing++;
            }
        }
        $missingReturns = 0;
        foreach ($returns as $ret) {
            $rows = $returnsByHeader->get($ret->id, collect());
            if ($rows->isEmpty() || $sumMoney($rows, 'amount_minor') !== (string) $ret->amount_minor) {
                $missingReturns++;
            }
        }
        $weeks = [];
        for ($cursor = $from; $cursor->lte($to); $cursor = $cursor->addWeek()) {
            $weeks[$cursor->toDateString()] = ['from' => $cursor->toDateString(), 'to' => $cursor->addDays(6)->toDateString(), 'sold_milli' => 0, 'returned_milli' => 0, 'observed_lines' => 0];
        }
        foreach ($allLines->where('product_id', $product->id) as $line) {
            $invoice = $invoicesById->get($line->invoice_id);
            $key = $invoice->invoice_date->startOfWeek()->toDateString();
            $weeks[$key]['sold_milli'] += (int) bcmul($line->quantity, '1000', 0);
            $weeks[$key]['observed_lines']++;
            foreach ($returnsByLine->get($line->id, collect()) as $rl) {
                $weeks[$key]['returned_milli'] += (int) bcmul($rl->quantity, '1000', 0);
            }
        }
        foreach ($weeks as &$w) {
            $w['net_quantity'] = ($w['sold_milli'] - $w['returned_milli']) / 1000;
            abort_if($w['sold_milli'] > 1000000000000, 422, 'كمية الأسبوع تتجاوز حد الحساب الآمن.');
        }unset($w);
        $observations = InventoryObservation::query()->where('source_system', $d['source_system'])->where('product_id', $product->id)->where(function ($q) use ($area) {
            $q->where('area_id', $area->id)->orWhereNull('area_id');
        })->where('observed_on', '<=', $d['to']);
        abort_if((clone $observations)->count() > 10000, 422, 'قسّم نطاق مدخلات المخزون.');
        $inputs = $observations->orderBy('id')->get();
        $stockouts = $inputs->where('kind', 'stockouts')->filter(fn ($x) => $x->end_date->toDateString() >= $d['from']);
        $fingerprint = hash('sha256', json_encode([$authority?->uuid, $d['from'], $d['to'], $d['source_system'], $product->uuid, $area->uuid, $cohort->toArray(), $allLines->toArray(), $returns->toArray(), $returnLines->toArray(), $inputs->toArray()], JSON_THROW_ON_ERROR));
        $warnings = ['التوقع تجريبي لكمية البيع قبل المرتجعات في الأسبوع التالي، ولا يمثل الطلب غير الملبى أو وعداً بالدقة.', 'توزع المرتجعات وصفياً على أسبوع الفاتورة الأصلية حتى نهاية الفترة؛ لا تدخل صافي الكميات أو المرتجعات المتأخرة في تدريب التوقع.', 'اعتبار الأسبوع بلا مبيعات صفراً يعتمد على إقرار التغطية البشرية، ولا يمكن إثباته من غياب السجلات.', 'نطاق الخطأ وصفي من أخطاء الاختبار الزمني ولا يحمل احتمال تغطية إحصائياً معتمداً.', 'آخر أربعة أسابيع محجوبة عن اختيار النموذج، لكنها أربع نقاط فقط ولا تكفي لإثبات دقة عامة.', 'الاختبار بأثر رجعي حسب تاريخ الفاتورة؛ قد تشمل البيانات تصحيحات واستيرادات لم تكن متاحة وقتها. يلزم قبول حي لإثبات الأداء المستقبلي.', 'لا تستخدم التكاليف أو السكان كمعامل سببي؛ لا يجري خلط العملات أو تقدير الأرباح.'];
        if ($stockouts->isNotEmpty()) {
            $warnings[] = 'توجد انقطاعات مسجلة؛ الكميات المباعة قد تقل عن الطلب. لم تُقدّر المبيعات المفقودة.';
        }
        if ($inputs->where('kind', 'stock_snapshots')->isEmpty()) {
            $warnings[] = 'لا توجد أرصدة مخزون مؤرخة لهذا النطاق.';
        }
        $ready = $missing === 0 && $missingReturns === 0 && $allLines->where('product_id', $product->id)->isNotEmpty();
        $result = ['source_authority' => $authority ? ['uuid' => $authority->uuid, 'version' => $authority->version, 'source_system' => $authority->source_system] : null, 'algorithm_version' => 'weekly_baselines_v2_holdout', 'product' => ['uuid' => $product->uuid, 'code' => $product->code, 'unit' => $product->base_unit], 'area' => ['uuid' => $area->uuid, 'code' => $area->code], 'scope' => array_intersect_key($d, array_flip(['source_system', 'from', 'to'])), 'fingerprint' => $fingerprint, 'series' => array_values($weeks), 'readiness' => ['complete_invoice_count' => $count - $missing, 'incomplete_invoice_count' => $missing, 'incomplete_return_count' => $missingReturns, 'stockout_count' => $stockouts->count(), 'coverage_note' => $d['coverage_note'], 'coverage_acknowledged_by_human' => true], 'status' => $ready ? 'experimental' : 'insufficient_data', 'warnings' => $warnings, 'forecast' => null];
        if (! $ready) {
            return $result;
        }
        $y = array_map(fn ($w) => $w['sold_milli'] / 1000, array_values($weeks));
        $n = count($y);
        $errors = ['last_week' => [], 'mean_4_weeks' => []];
        $origins = [];
        $holdoutStart = $n - 4;
        for ($i = 6; $i < $n; $i++) {
            $pred = ['last_week' => $y[$i - 1], 'mean_4_weeks' => array_sum(array_slice($y, $i - 4, 4)) / 4];
            foreach ($pred as $m => $v) {
                $errors[$m][] = abs($y[$i] - $v);
            }$origins[] = ['phase' => $i < $holdoutStart ? 'selection' : 'holdout', 'training_to' => array_values($weeks)[$i - 1]['to'], 'test_from' => array_values($weeks)[$i]['from'], 'actual' => $y[$i], ...$pred];
        }
        $selectionErrors = array_map(fn ($x) => array_slice($x, 0, $holdoutStart - 6), $errors);
        $holdoutErrors = array_map(fn ($x) => array_slice($x, $holdoutStart - 6), $errors);
        $mae = array_map(fn ($x) => array_sum($x) / count($x), $selectionErrors);
        $holdoutMae = array_map(fn ($x) => array_sum($x) / count($x), $holdoutErrors);
        $winner = $mae['mean_4_weeks'] < $mae['last_week'] ? 'mean_4_weeks' : 'last_week';
        $estimate = $winner === 'last_week' ? $y[$n - 1] : array_sum(array_slice($y, -4)) / 4;
        $residuals = $errors[$winner];
        sort($residuals);
        $radius = $residuals[(int) ceil(count($residuals) * 0.9) - 1];
        $result['backtest'] = ['method' => 'expanding_origin_one_week', 'model_selection_uses_this_backtest' => false, 'selection_uses_earlier_origins_only' => true, 'independent_holdout' => true, 'holdout_weeks' => 4, 'holdout_from' => array_values($weeks)[$holdoutStart]['from'], 'mae' => $mae, 'holdout_mae' => $holdoutMae, 'selection_origins' => $holdoutStart - 6, 'holdout_selected_model_mae' => $holdoutMae[$winner], 'origins' => $origins];
        $result['forecast'] = ['model' => $winner, 'from' => $to->addDay()->toDateString(), 'to' => $to->addWeek()->toDateString(), 'quantity' => round($estimate, 3), 'lower' => round(max(0, $estimate - $radius), 3), 'upper' => round($estimate + $radius, 3), 'interval' => 'empirical_absolute_error_90th_percentile', 'calibrated' => false, 'target' => 'gross_sold_quantity'];

        return $result;
    }
}
