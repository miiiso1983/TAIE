<?php

namespace App\Modules\Billing\Services;

use App\Core\Tenancy\TenantContext;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Billing\Models\UsageRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(private TenantContext $context) {}

    public function recordUsage(string $metric, int $quantity, string $key, array $metadata = []): UsageRecord
    {
        $tenantId = $this->context->getTenantId();

        return DB::transaction(function () use ($tenantId, $metric, $quantity, $key, $metadata) {
            $existing = UsageRecord::query()->where('tenant_id', $tenantId)->where('metric', $metric)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            return UsageRecord::query()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'metric' => $metric, 'quantity' => $quantity, 'idempotency_key' => $key, 'occurred_at' => now(), 'metadata' => $metadata]);
        });
    }

    public function currentSubscription(): ?TenantSubscription
    {
        return $this->entitledSubscription() ?? TenantSubscription::query()->with('plan')->latest('starts_at')->first();
    }

    public function entitledSubscription(): ?TenantSubscription
    {
        return TenantSubscription::query()->with('plan')->whereIn('status', ['active', 'trialing'])
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->where(fn ($q) => $q->where('status', 'active')->orWhere('trial_ends_at', '>', now()))
            ->latest('starts_at')->first();
    }

    public function assertSeatAvailable(): void
    {
        if (! config('teami.billing_enforce')) {
            return;
        }
        $subscription = $this->entitledSubscription();
        abort_unless($subscription, 403, 'An active subscription is required to add users.');
        $limit = $subscription->plan->limits['users'] ?? 0;
        $count = $this->context->requireTenant()->users()->wherePivot('status', 'active')->count();
        abort_if($count >= $limit, 409, 'The plan user limit has been reached.');
    }
}
