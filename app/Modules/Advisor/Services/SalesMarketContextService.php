<?php

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Models\MarketContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesMarketContextService
{
    public function describe(array $filters, array $invoices): array
    {
        $basis = $filters['fx_basis'] ?? 'official';
        $reference = $filters['fx_reference_date'] ?? $filters['from'];
        $base = ['available' => Schema::hasTable('advisor_market_context'), 'rate_basis' => $basis, 'reference_date' => $reference, 'country' => 'IQ', 'valuation' => 'invoice_date_net_cohort_not_realized_fx', 'warnings' => ['الرسمي والسوق مساران منفصلان؛ الأسعار مدخلة بمراجعة بشرية من مصادر مؤرخة وليست تغذية حية.', 'تقييم صافي الفاتورة بسعر يومها، بما فيه المرتجع حتى تاريخ القياس، مقارنة وصفية وليست حساب ربح صرف أو محاسبة تحصيلات.', 'غياب سعر يوم الفاتورة يمنع إجمالي التحويل؛ لا يستخدم سعر اليوم الحالي أو تخمين أو خلط مسارات.', 'تغير القيمة بسعر ثابت لا يعزل التضخم أو تغير الأسعار أو مزيج المنتجات أو كمية الطلب.', 'الأحداث السياسية سياق موثق وقنوات أثر محتملة، وليست إثباتاً سببياً أو مبرراً آلياً لخفض هدف أو تغيير مكافأة.']];
        if (! $base['available']) {
            return [...$base, 'status' => 'upgrade_required', 'fx' => null, 'events' => []];
        }
        $dates = array_values(array_unique([$reference, ...array_column($invoices, 'invoice_date')]));
        $rates = MarketContext::query()->where('status', 'active')->where('kind', 'fx')->where('rate_basis', $basis)->whereIn(DB::raw('DATE(period_from)'), $dates)->get()->keyBy(fn ($row) => $row->period_from->toDateString());
        $referenceRow = $rates->get($reference);
        $referenceRate = $referenceRow?->payload_encrypted['iqd_per_usd'] ?? null;
        $observed = '0.000000';
        $constant = '0.000000';
        $missing = [];
        $hasIqd = false;
        $used = [];
        foreach ($invoices as $invoice) {
            if ($invoice['currency'] === 'USD') {
                $value = bcdiv($invoice['net_sales_minor'], '100', 6);
                $observed = bcadd($observed, $value, 6);
                $constant = bcadd($constant, $value, 6);

                continue;
            }
            $hasIqd = true;
            $rateRow = $rates->get($invoice['invoice_date']);
            if (! $rateRow) {
                $missing[] = $invoice['invoice_date'];
            } else {
                $rate = $rateRow->payload_encrypted['iqd_per_usd'];
                $observed = bcadd($observed, bcdiv($invoice['net_sales_minor'], bcmul($rate, '1000', 6), 6), 6);
                $used[$rateRow->uuid] = $this->rateEvidence($rateRow);
            }
            if ($referenceRate !== null) {
                $constant = bcadd($constant, bcdiv($invoice['net_sales_minor'], bcmul($referenceRate, '1000', 6), 6), 6);
            }
        }
        if ($hasIqd && $referenceRow) {
            $used[$referenceRow->uuid] = $this->rateEvidence($referenceRow);
        }
        $complete = count($missing) === 0;
        $constantComplete = ! $hasIqd || $referenceRate !== null;
        $eventsQuery = MarketContext::query()->where('status', 'active')->where('kind', 'political')->whereDate('period_from', '<=', $filters['to'])->whereDate('period_to', '>=', $filters['from']);
        $totalEvents = (clone $eventsQuery)->count();
        $events = $eventsQuery->orderBy('period_from')->orderBy('id')->limit(100)->get()->map(fn ($row) => ['uuid' => $row->uuid, 'from' => $row->period_from->toDateString(), 'to' => $row->period_to->toDateString(), 'area_uuid' => $row->area_uuid, 'scope_notice' => $row->area_uuid ? 'حدث لمنطقة محددة؛ لا يفترض تأثيره على جميع المندوبين.' : 'سياق العراق العام؛ مقدار تأثيره على الشركة غير مثبت.', 'evidence' => $row->payload_encrypted, 'reviewed_at' => $row->created_at->toISOString()])->all();

        return [...$base, 'status' => $complete && $constantComplete ? 'reviewed_context' : 'missing_rates', 'fx' => ['observed_usd' => $complete ? $observed : null, 'constant_usd' => $constantComplete ? $constant : null, 'conversion_difference_usd' => $complete && $constantComplete ? bcsub($observed, $constant, 6) : null, 'missing_invoice_dates' => array_values(array_unique($missing)), 'missing_reference_rate' => $hasIqd && ! $constantComplete, 'rate_evidence' => array_values($used), 'precision' => 'USD major units truncated per invoice to 6 decimals'], 'events' => $events, 'event_count' => $totalEvents, 'events_truncated' => $totalEvents > 100];
    }

    private function rateEvidence(MarketContext $row): array
    {
        return ['uuid' => $row->uuid, 'date' => $row->period_from->toDateString(), 'basis' => $row->rate_basis, 'iqd_per_usd' => $row->payload_encrypted['iqd_per_usd'], 'source_url' => $row->payload_encrypted['source_url'], 'publisher' => $row->payload_encrypted['publisher'], 'source_published_on' => $row->payload_encrypted['source_published_on'], 'reviewed_at' => $row->created_at->toISOString()];
    }

    public function bothPeriods(array $currentFilters, array $previousFilters, array $current, array $previous): array
    {
        $result = [];
        foreach (['official', 'market'] as $basis) {
            $c = $this->describe([...$currentFilters, 'fx_basis' => $basis], $current['evidence']);
            $p = $this->describe([...$previousFilters, 'fx_basis' => $basis], $previous['evidence']);
            $result[$basis] = ['current' => $c, 'previous' => $p, 'comparison' => $this->comparison($c, $p)];
        }

        return $result;
    }

    public function comparison(array $current, array $previous): array
    {
        $growth = function ($now, $before) {
            return $now !== null && $before !== null && bccomp($before, '0', 6) > 0 ? bcdiv(bcmul(bcsub($now, $before, 6), '100', 6), $before, 2) : null;
        };

        return ['observed_usd_growth_percent' => $growth($current['fx']['observed_usd'] ?? null, $previous['fx']['observed_usd'] ?? null), 'constant_usd_growth_percent' => $growth($current['fx']['constant_usd'] ?? null, $previous['fx']['constant_usd'] ?? null), 'notice' => 'فترتان متساويتان بعدد الأيام وبسعر مرجعي واحد. لا يثبت نمو القيمة بسعر ثابت نمو الكمية أو أثراً سياسياً سببياً.'];
    }
}
