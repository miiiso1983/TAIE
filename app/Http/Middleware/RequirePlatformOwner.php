<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->status === 'active' && Schema::hasTable('platform_owners') && DB::table('platform_owners')->where('user_id', $request->user()->id)->exists(), 403, 'Platform owner access required.');

        return $next($request);
    }
}
