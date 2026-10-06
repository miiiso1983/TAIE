<?php

namespace App\Core\Events;

use App\Core\Logging\SecurityEventLogger;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WebhookInbox
{
    public function __construct(private SecurityEventLogger $securityEvents) {}

    public function receive(string $provider, string $eventId, array $payload, string $signature, string $secret): WebhookEvent
    {
        if ($provider === '' || strlen($provider) > 100 || $eventId === '' || strlen($eventId) > 255 || $secret === '') {
            throw new InvalidArgumentException('Invalid webhook envelope.');
        }
        $canonical = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (! hash_equals($expected, strtolower($signature))) {
            $this->securityEvents->record('invalid_webhook_signature', 'high', ['provider' => $provider, 'event_id' => $eventId], 'Webhook signature validation failed.', 'request_rejected');
            throw new InvalidArgumentException('Invalid webhook signature.');
        }

        return DB::transaction(function () use ($provider, $eventId, $payload) {
            $existing = WebhookEvent::query()->where('provider', $provider)->where('event_id', $eventId)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            return WebhookEvent::query()->create(['provider' => $provider, 'event_id' => $eventId, 'payload' => $this->redact($payload), 'signature_valid' => true, 'status' => 'received']);
        });
    }

    private function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/password|token|secret|api.?key|medical|bank|national.?id|salary|ssn|passport|email|phone|address|birth/i', (string) $key)) {
                $payload[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
