<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Tenant');
        abort_if(! is_string($key) || $key === '', 404, 'Tenant not found.');
        $tenant = Tenant::query()->where('uuid', $key)->orWhere('slug', $key)->first();
        abort_unless($tenant, 404, 'Tenant not found.');
        $this->context->setTenant($tenant);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
