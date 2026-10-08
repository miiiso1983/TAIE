<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\MarketContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MarketContextController extends Controller
{
    private function ready(): void
    {
        abort_unless(Schema::hasTable('advisor_market_context'), 503, 'Market context requires the pending schema upgrade.');
    }

    private function summary(MarketContext $row): array
    {
        return [...$row->toArray(), 'period_from' => $row->period_from->toDateString(), 'period_to' => $row->period_to->toDateString(), 'evidence' => $row->payload_encrypted, 'revocation_note' => $row->revocation_note_encrypted];
    }

    public function index(Request $r)
    {
        $this->ready();
        $d = $r->validate(['kind' => ['nullable', 'in:fx,political']]);

        return response()->json(['data' => MarketContext::query()->when($d['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))->latest('id')->paginate(20)->through(fn ($row) => $this->summary($row))]);
    }

    public function store(Request $r, AuditTrailService $audit)
    {
        $this->ready();
        $today = now(app(TenantContext::class)->requireTenant()->timezone ?: 'UTC')->toDateString();
        $d = $r->validate(['kind' => ['required', 'in:fx,political'], 'period_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:'.$today], 'area_uuid' => ['nullable', 'uuid'], 'rate_basis' => ['required_if:kind,fx', 'prohibited_unless:kind,fx', 'in:official,market'], 'iqd_per_usd' => ['required_if:kind,fx', 'prohibited_unless:kind,fx', 'string', 'regex:/^[1-9][0-9]{0,5}(\.[0-9]{1,6})?$/'], 'title' => ['required', 'string', 'max:180'], 'category' => ['required_if:kind,political', 'prohibited_unless:kind,political', 'in:security,border_transport,payment_restriction,economic_policy,other'], 'potential_channel' => ['required_if:kind,political', 'prohibited_unless:kind,political', 'string', 'min:12', 'max:1000'], 'source_url' => ['required', 'url:https', 'max:1000'], 'publisher' => ['required', 'string', 'max:180'], 'source_published_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'review_note' => ['required', 'string', 'min:12', 'max:1000'], 'acknowledge' => ['required', 'accepted']]);
        if ($d['kind'] === 'fx') {
            abort_unless($d['period_from'] === $d['period_to'] && empty($d['area_uuid']), 422, 'سعر التحليل يومي على نطاق العراق؛ لا تستخدم متوسط فترة كسعر يومي.');
            if ($d['rate_basis'] === 'official') {
                abort_unless(in_array(strtolower((string) parse_url($d['source_url'], PHP_URL_HOST)), ['cbi.iq', 'www.cbi.iq']), 422, 'السعر الرسمي يحتاج مرجع البنك المركزي؛ مصادر السوق تحفظ كمسار سوق مستقل.');
            }
        }
        if (! empty($d['area_uuid'])) {
            Area::query()->where('uuid', $d['area_uuid'])->firstOrFail();
        }

        return DB::transaction(function () use ($r, $d, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            if ($d['kind'] === 'fx') {
                abort_if(MarketContext::query()->where('kind', 'fx')->where('status', 'active')->where('rate_basis', $d['rate_basis'])->whereDate('period_from', $d['period_from'])->exists(), 409, 'يوجد سعر نشط لهذا اليوم والمسار؛ ألغِ المرجع القديم بسبب موثق قبل تصحيحه.');
            }
            $row = MarketContext::query()->create(['uuid' => Str::uuid(), 'kind' => $d['kind'], 'rate_basis' => $d['rate_basis'] ?? null, 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'area_uuid' => $d['area_uuid'] ?? null, 'reviewed_by' => $r->user()->id, 'payload_encrypted' => array_intersect_key($d, array_flip(['iqd_per_usd', 'title', 'category', 'potential_channel', 'source_url', 'publisher', 'source_published_on', 'review_note']))]);
            $audit->record('advisor.market_context.reviewed', ['module' => 'advisor', 'entity_id' => $row->uuid, 'new_values' => ['kind' => $row->kind]]);

            return response()->json(['data' => $this->summary($row->refresh())], 201);
        });
    }

    public function revoke(Request $r, string $uuid, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['note' => ['required', 'string', 'min:12', 'max:1000'], 'acknowledge' => ['required', 'accepted']]);

        return DB::transaction(function () use ($r, $uuid, $d, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $row = MarketContext::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'active', 409);
            $row->update(['status' => 'revoked', 'revoked_by' => $r->user()->id, 'revoked_at' => now(), 'revocation_note_encrypted' => $d['note']]);
            $audit->record('advisor.market_context.revoked', ['module' => 'advisor', 'entity_id' => $row->uuid]);

            return response()->json(['data' => $this->summary($row)]);
        });
    }
}
