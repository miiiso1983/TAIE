<?php

namespace App\Http\Middleware;

use App\Core\Logging\SecurityEventLogger;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateMembership
{
    public function __construct(private TenantContext $context, private SecurityEventLogger $securityEvents) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && $request->user()->status === 'active', 401, 'Authentication required.');
        $tenant = $this->context->requireTenant();
        $member = $tenant->users()->whereKey($request->user()->getKey())->wherePivot('status', 'active')->exists();
        if (! $member) {
            $this->securityEvents->record('cross_tenant_attempt', 'high', ['tenant_uuid' => $tenant->uuid], 'User is not an active member of the selected tenant.', 'request_denied');
            abort(403, 'Tenant access denied.');
        }

        return $next($request);
    }
}
