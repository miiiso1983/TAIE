<?php

namespace App\Core\Logging;

use App\Core\Tenancy\TenantContext;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;

class SecurityEventLogger
{
    public function __construct(private TenantContext $context, private Request $request) {}

    public function record(string $type, string $severity = 'medium', array $payload = [], ?string $description = null, ?string $action = null): void
    {
        array_walk_recursive($payload, function (&$value, $key) {
            if (preg_match('/password|token|secret|api.?key|medical|bank|national.?id|salary|ssn|passport|email|phone|address|birth/i', (string) $key)) {
                $value = '[REDACTED]';
            }
        });
        SecurityEvent::query()->create(['tenant_id' => $this->context->hasTenant() ? $this->context->getTenantId() : null, 'user_id' => auth()->id(), 'event_type' => $type, 'severity' => $severity, 'description' => $description, 'payload_json' => $payload, 'action_taken' => $action, 'request_id' => $this->request->attributes->get('request_id'), 'created_at' => now()]);
    }
}
