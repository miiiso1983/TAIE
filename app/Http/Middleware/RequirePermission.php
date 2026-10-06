<?php

namespace App\Http\Middleware;

use App\Core\Authorization\PolicyEvaluator;
use App\Core\Logging\SecurityEventLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $request->user() || ! app(PolicyEvaluator::class)->allows($request->user(), $permission)) {
            app(SecurityEventLogger::class)->record('permission_violation', 'high', ['permission' => $permission], 'Authorization denied.', 'request_denied');
            abort(403, 'Access denied.');
        }

        return $next($request);
    }
}
