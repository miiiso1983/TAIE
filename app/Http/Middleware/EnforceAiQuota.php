<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Billing\Models\UsageRecord;
use App\Modules\Billing\Services\BillingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EnforceAiQuota
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('teami.billing_enforce')) {
            return $next($request);
        }
        $usage = DB::transaction(function () {
            $tenant = app(TenantContext::class)->requireTenant();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $subscription = app(BillingService::class)->entitledSubscription();
            abort_unless($subscription, 403, 'An active subscription is required for the AI assistant.');
            $trialLimit = $subscription->status === 'trialing' ? ($subscription->plan->limits['ai_requests_per_trial'] ?? null) : null;
            $local = now($tenant->timezone ?: 'UTC')->startOfMonth();
            $start = $local->copy()->utc();
            $end = $local->copy()->addMonth()->utc();
            $start = $trialLimit !== null ? $subscription->starts_at : $start;
            $end = $trialLimit !== null ? $subscription->trial_ends_at : $end;
            $used = UsageRecord::query()->where('metric', 'ai.requests')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->sum('quantity');
            abort_if($used >= ($trialLimit ?? $subscription->plan->limits['ai_requests_per_month'] ?? 0), 429, $trialLimit !== null ? 'استُخدمت الطلبات المجانية الـ25. اختر خطة للمتابعة.' : 'The monthly AI request limit has been reached.');

            return app(BillingService::class)->recordUsage('ai.requests', 1, (string) Str::uuid());
        });
        try {
            $response = $next($request);
            if (! $response->isSuccessful()) {
                $usage->delete();
            }

            return $response;
        } catch (Throwable $e) {
            $usage->delete();
            throw $e;
        }
    }
}
