<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\UsageRecord;
use App\Modules\Billing\Services\BillingService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function subscription(BillingService $billing)
    {
        $subscription = $billing->currentSubscription();

        return response()->json(['data' => $subscription ? ['uuid' => $subscription->uuid, 'status' => $subscription->status, 'starts_at' => $subscription->starts_at?->toISOString(), 'ends_at' => $subscription->ends_at?->toISOString(), 'trial_ends_at' => $subscription->trial_ends_at?->toISOString(), 'plan' => ['code' => $subscription->plan->code, 'name' => $subscription->plan->name, 'currency' => $subscription->plan->currency, 'unit_amount' => $subscription->plan->unit_amount, 'interval' => $subscription->plan->interval, 'limits' => $subscription->plan->limits]] : null]);
    }

    public function usage(Request $request, TenantContext $context)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'metric' => ['nullable', 'string', 'max:100']]);
        $query = UsageRecord::query()->where('tenant_id', $context->getTenantId())->when($data['from'] ?? null, fn ($q, $from) => $q->where('occurred_at', '>=', $from))->when($data['to'] ?? null, fn ($q, $to) => $q->where('occurred_at', '<=', $to))->when($data['metric'] ?? null, fn ($q, $metric) => $q->where('metric', $metric));
        $totals = (clone $query)->selectRaw('metric, SUM(quantity) as quantity')->groupBy('metric')->get()->mapWithKeys(fn ($row) => [$row->metric => (int) $row->quantity]);

        return response()->json(['data' => ['totals' => $totals, 'records' => $query->latest('occurred_at')->paginate(100)->through(fn ($row) => ['uuid' => $row->uuid, 'metric' => $row->metric, 'quantity' => $row->quantity, 'occurred_at' => $row->occurred_at->toISOString()])]]);
    }

    public function recordUsage(Request $request, BillingService $billing)
    {
        $data = $request->validate(['metric' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000000'], 'idempotency_key' => ['required', 'string', 'max:128'], 'metadata' => ['sometimes', 'array', 'max:20']]);
        $record = $billing->recordUsage($data['metric'], $data['quantity'], $data['idempotency_key'], $data['metadata'] ?? []);

        return response()->json(['data' => ['uuid' => $record->uuid, 'metric' => $record->metric, 'quantity' => $record->quantity, 'occurred_at' => $record->occurred_at->toISOString()]], 201);
    }
}
