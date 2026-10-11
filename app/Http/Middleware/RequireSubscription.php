<?php

namespace App\Http\Middleware;

use App\Modules\Billing\Services\BillingService;
use Closure;
use Illuminate\Http\Request;

class RequireSubscription
{
    public function handle(Request $request, Closure $next)
    {
        if (config('teami.billing_enforce') && ! $request->isMethod('GET') && $request->is('api/hr/*', 'api/sales/*', 'api/knowledge/*', 'api/ai/*', 'api/advisor/*', 'api/integrations/*', 'api/files', 'api/files/*')) {
            abort_unless(app(BillingService::class)->entitledSubscription(), 403, 'An active subscription is required for business changes. Billing and read access remain available.');
        }

        return $next($request);
    }
}
