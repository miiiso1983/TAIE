<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceAiQuota;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireSubscription;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
        $middleware->alias([
            'billing.access' => RequireSubscription::class,
            'billing.ai-quota' => EnforceAiQuota::class,
            'tenant.resolve' => ResolveTenant::class,
            'tenant.membership' => ValidateMembership::class,
            'tenant.active' => EnsureTenantActive::class,
            'idempotency' => Idempotency::class,
            'permission' => RequirePermission::class,
        ]);
        $middleware->api(append: [AssignRequestId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            if (! $request->attributes->get('api_error_envelope')) {
                return null;
            }
            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                $e instanceof ModelNotFoundException => 404,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                401 => 'AUTHENTICATION_REQUIRED', 403 => 'TENANT_ACCESS_DENIED', 404 => 'RESOURCE_NOT_FOUND',
                409 => 'IDEMPOTENCY_CONFLICT', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED', default => 'INTERNAL_ERROR',
            };
            $message = $status >= 500 ? 'An unexpected error occurred.' : $e->getMessage();

            return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message, ...($e instanceof ValidationException ? ['details' => $e->errors()] : [])], 'request_id' => $request->attributes->get('request_id')], $status, ['X-Request-ID' => $request->attributes->get('request_id')]);
        });
    })->create();
