<?php

namespace App\Core\Events;

use App\Core\Tenancy\TenantContext;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Outbox
{
    public function __construct(private TenantContext $context) {}

    public function append(string $type, array $payload): OutboxEvent
    {
        return OutboxEvent::query()->create(['tenant_id' => $this->context->hasTenant() ? $this->context->getTenantId() : null, 'event_uuid' => (string) Str::uuid(), 'event_type' => $type, 'payload' => $payload, 'status' => 'pending', 'available_at' => now()]);
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
