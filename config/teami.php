<?php

return [
    'billing_enforce' => (bool) env('BILLING_ENFORCE', false),
    'audit_hmac_key' => env('AUDIT_HMAC_KEY'),
    'idempotency_ttl_seconds' => (int) env('IDEMPOTENCY_TTL_SECONDS', 86400),
    'queues' => ['default', 'critical', 'ai', 'rag', 'documents', 'notifications', 'billing', 'analytics'],
];
