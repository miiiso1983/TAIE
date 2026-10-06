<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Billing\Models\BillingPayment;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManualBillingController extends Controller
{
    public function plans()
    {
        return response()->json(['data' => BillingPlan::query()->where('is_active', true)->get(['uuid', 'code', 'name', 'currency', 'unit_amount', 'interval', 'limits'])]);
    }

    public function invoices()
    {
        return response()->json(['data' => BillingInvoice::query()->latest('id')->paginate(25)]);
    }

    public function payments()
    {
        return response()->json(['data' => BillingPayment::query()->latest('id')->paginate(25)]);
    }

    public function createPlan(Request $r, AuditTrailService $audit)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', Rule::unique('billing_plans', 'code')], 'name' => ['required', 'string', 'max:120'], 'currency' => ['required', Rule::in(['USD', 'IQD'])], 'unit_amount' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'interval' => ['required', Rule::in(['month', 'year'])], 'limits' => ['required', 'array:users,ai_requests_per_month'], 'limits.users' => ['required', 'integer', 'min:1', 'max:100000'], 'limits.ai_requests_per_month' => ['required', 'integer', 'min:0', 'max:1000000']]);
        $plan = DB::transaction(function () use ($d, $audit) {
            $plan = BillingPlan::query()->create(['uuid' => (string) Str::uuid(), ...$d]);
            $audit->record('billing.plan.created', ['module' => 'billing', 'entity_type' => 'plan', 'entity_id' => $plan->uuid]);

            return $plan;
        });

        return response()->json(['data' => $plan], 201);
    }

    public function issue(Request $r, TenantContext $context, AuditTrailService $audit, Outbox $outbox)
    {
        $d = $r->validate(['plan_uuid' => ['required', 'uuid'], 'starts_at' => ['required', 'date'], 'due_at' => ['required', 'date']]);
        $invoice = DB::transaction(function () use ($d, $context, $audit, $outbox) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $plan = BillingPlan::query()->where('uuid', $d['plan_uuid'])->where('is_active', true)->firstOrFail();
            $start = CarbonImmutable::parse($d['starts_at'])->startOfDay();
            $end = $plan->interval === 'year' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
            abort_if(TenantSubscription::query()->whereIn('status', ['pending', 'active', 'trialing'])->where('starts_at', '<', $end)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))->exists(), 409, 'Subscription periods cannot overlap.');
            $sub = TenantSubscription::query()->create(['uuid' => (string) Str::uuid(), 'billing_plan_id' => $plan->id, 'status' => 'pending', 'starts_at' => $start, 'ends_at' => $end]);
            $invoice = BillingInvoice::query()->create(['uuid' => (string) Str::uuid(), 'subscription_id' => $sub->id, 'number' => 'TM-'.strtoupper((string) Str::ulid()), 'plan_name' => $plan->name, 'currency' => $plan->currency, 'amount_minor' => $plan->unit_amount, 'period_start' => $start, 'period_end' => $end, 'due_at' => $d['due_at'], 'status' => 'open']);
            $audit->record('billing.invoice.issued', ['module' => 'billing', 'entity_type' => 'invoice', 'entity_id' => $invoice->uuid]);
            $outbox->append('billing.invoice.issued', ['invoice_uuid' => $invoice->uuid]);

            return $invoice;
        });

        return response()->json(['data' => $invoice], 201);
    }

    public function report(Request $r, string $uuid, AuditTrailService $audit)
    {
        $r->merge(['reference' => strtoupper(trim((string) $r->input('reference')))]);
        $d = $r->validate(['reference' => ['required', 'string', 'max:120'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000']]);
        $p = DB::transaction(function () use ($r, $uuid, $d, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $i = BillingInvoice::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($i->status === 'open', 409, 'Invoice is not open.');
            abort_unless((int) $d['amount_minor'] === $i->amount_minor, 422, 'Report the exact invoice amount; partial payments are not supported.');
            abort_if(BillingPayment::query()->where('reference', $d['reference'])->exists() || BillingPayment::query()->where('invoice_id', $i->id)->where('status', 'pending')->exists(), 409, 'Payment reference or pending report already exists.');
            $p = BillingPayment::query()->create(['uuid' => (string) Str::uuid(), 'invoice_id' => $i->id, 'reference' => $d['reference'], 'amount_minor' => $d['amount_minor'], 'reported_by' => $r->user()->id]);
            $audit->record('billing.payment.reported', ['module' => 'billing', 'entity_type' => 'payment', 'entity_id' => $p->uuid]);

            return $p;
        });

        return response()->json(['data' => $p], 201);
    }

    public function decide(Request $r, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['required', 'string', 'max:2000']]);
        $p = DB::transaction(function () use ($r, $uuid, $d, $audit, $outbox) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $p = BillingPayment::query()->where('uuid', $uuid)->firstOrFail();
            $i = BillingInvoice::query()->whereKey($p->invoice_id)->lockForUpdate()->firstOrFail();
            $p = BillingPayment::query()->whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->status === 'pending' && $i->status === 'open', 409, 'Payment has already been reviewed or invoice is closed.');
            abort_if($p->reported_by === $r->user()->id, 403, 'A different operator must verify the transfer.');
            if ($d['decision'] === 'approve') {
                abort_if($i->period_end->isPast(), 409, 'The billed period has expired; reject this report and issue a current invoice.');
                $i->update(['status' => 'paid', 'paid_at' => now()]);
                TenantSubscription::query()->whereKey($i->subscription_id)->update(['status' => 'active']);
            }
            $p->update(['status' => $d['decision'] === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'review_note' => $d['note']]);
            $audit->record('billing.payment.reviewed', ['module' => 'billing', 'entity_type' => 'payment', 'entity_id' => $p->uuid, 'new_values' => ['status' => $p->status]]);
            $outbox->append('billing.payment.reviewed', ['payment_uuid' => $p->uuid, 'status' => $p->status]);

            return $p;
        });

        return response()->json(['data' => $p]);
    }

    public function void(string $uuid, AuditTrailService $audit)
    {
        DB::transaction(function () use ($uuid, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $i = BillingInvoice::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($i->status === 'open', 409, 'Only an open invoice can be voided.');
            abort_if(BillingPayment::query()->where('invoice_id', $i->id)->where('status', 'pending')->exists(), 409, 'Review the pending transfer report first.');
            $i->update(['status' => 'void']);
            TenantSubscription::query()->whereKey($i->subscription_id)->update(['status' => 'canceled']);
            $audit->record('billing.invoice.voided',['module' => 'billing', 'entity_type' => 'invoice', 'entity_id' => $i->uuid]);
        });

        return response()->json(['data' => ['uuid' => $uuid, 'status' => 'void']]);
    }
}
