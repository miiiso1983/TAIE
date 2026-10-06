<?php

namespace App\Http\Middleware;

use App\Core\Logging\SecurityEventLogger;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantActive
{
    public function __construct(private TenantContext $context, private SecurityEventLogger $securityEvents) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->requireTenant()->status !== 'active') {
            $this->securityEvents->record('tenant_suspended', 'high', [], 'Access attempted for an inactive tenant.', 'request_denied');
            abort(403, 'Tenant is suspended.');
        }

        return $next($request);
    }
}
