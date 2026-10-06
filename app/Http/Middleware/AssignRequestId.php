<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('api_error_envelope', true);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
