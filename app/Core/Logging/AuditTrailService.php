<?php

namespace App\Core\Logging;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AuditTrailService implements AuditLoggerInterface
{
    public function __construct(private TenantContext $context, private Request $request) {}

    public function record(string $action, array $data = []): void
    {
        $key = (string) config('teami.audit_hmac_key');
        if (strlen($key) < 32) {
            throw new RuntimeException('AUDIT_HMAC_KEY must contain at least 32 characters.');
        }
        $tenantId = $this->context->hasTenant() ? $this->context->getTenantId() : null;
        DB::transaction(function () use ($key, $tenantId, $action, $data) {
            if ($tenantId !== null) {
                DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            }
            $previous = AuditLog::query()->where('tenant_id', $tenantId)->latest('id')->first();
            $row = [
                'tenant_id' => $tenantId, 'user_id' => $data['user_id'] ?? auth()->id(), 'action' => $action,
                'module' => $data['module'] ?? null, 'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => isset($data['entity_id']) ? (string) $data['entity_id'] : null,
                'old_values' => $this->redact($data['old_values'] ?? null), 'new_values' => $this->redact($data['new_values'] ?? null),
                'ip_address' => $this->request->ip(), 'user_agent' => $this->request->userAgent(),
                'request_id' => $this->request->attributes->get('request_id'), 'previous_hash' => $previous?->current_hash,
                'created_at' => now()->toISOString(),
            ];
            $canonical = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $row['canonical_payload'] = $canonical;
            $row['current_hash'] = hash_hmac('sha256', $canonical, $key);
            AuditLog::query()->create($row);
        });
    }

    public function verifyChain(?int $tenantId): bool
    {
        $key = (string) config('teami.audit_hmac_key');
        if (strlen($key) < 32) {
            return false;
        }
        $previous = null;
        foreach (AuditLog::query()->where('tenant_id', $tenantId)->orderBy('id')->cursor() as $entry) {
            $payload = json_decode($entry->canonical_payload, true);
            if (! is_array($payload) || ($payload['previous_hash'] ?? null) !== $previous) {
                return false;
            }
            $expected = hash_hmac('sha256', $entry->canonical_payload, $key);
            if (! hash_equals($expected, $entry->current_hash)) {
                return false;
            }
            $previous = $entry->current_hash;
        }

        return true;
    }

    private function redact(mixed $values): mixed
    {
        if (! is_array($values)) {
            return $values;
        }
        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|api.?key|medical|bank|national.?id|salary|ssn|passport|email|phone|address|birth/i', (string) $key)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
