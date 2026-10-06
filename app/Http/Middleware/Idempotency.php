<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return $next($request);
        }
        abort_if(strlen($key) > 128, 422, 'Invalid idempotency key.');
        $context = app(TenantContext::class);
        abort_unless($context->hasTenant() && $request->user(), 403, 'Tenant context required for idempotency.');
        $tenantId = $context->getTenantId();
        $endpoint = $request->method().' '.$request->path();
        $hash = hash('sha256', $request->getContent());
        if ($request->allFiles()) {
            // Multipart boundaries change between retries; hash values and file bytes.
            $normalize = function ($value) use (&$normalize) {
                if ($value instanceof UploadedFile) {
                    abort_unless($value->isValid(), 422, 'Invalid uploaded file.');

                    return ['name' => $value->getClientOriginalName(), 'sha256' => hash_file('sha256', $value->getRealPath())];
                }
                if (is_array($value)) {
                    ksort($value);

                    return array_map($normalize, $value);
                }

                return $value;
            };
            $hash = hash('sha256', json_encode($normalize($request->all()), JSON_THROW_ON_ERROR));
        }

        return DB::transaction(function () use ($request, $next, $key, $tenantId, $endpoint, $hash) {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $record = IdempotencyKey::query()->where('tenant_id', $tenantId)->where('user_id', $request->user()->getAuthIdentifier())->where('endpoint', $endpoint)->where('key', $key)->lockForUpdate()->first();
            if ($record && $record->expires_at->isFuture()) {
                abort_unless(hash_equals($record->request_hash, $hash), 409, 'Idempotency key was used with a different request.');
                if ($record->status === 'completed') {
                    return response()->json($record->response_encrypted ?? $record->response_body, $record->response_status);
                }
                abort(409, 'A request with this idempotency key is already in progress.');
            }
            $record ??= new IdempotencyKey;
            $record->fill(['tenant_id' => $tenantId, 'user_id' => $request->user()->getAuthIdentifier(), 'endpoint' => $endpoint, 'key' => $key, 'request_hash' => $hash, 'status' => 'processing', 'expires_at' => now()->addSeconds(config('teami.idempotency_ttl_seconds'))]);
            $record->save();
            $response = $next($request);
            if ($response->isSuccessful()) {
                $body = json_decode($response->getContent(), true);
                $record->forceFill(['status' => 'completed', 'response_status' => $response->getStatusCode(), 'response_body' => null, 'response_encrypted' => is_array($body) ? $body : ['content' => $response->getContent()]])->save();
            } else {
                $record->delete();
            }

            return $response;
        });
    }
}
