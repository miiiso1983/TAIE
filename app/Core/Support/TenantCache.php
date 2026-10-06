<?php

namespace App\Core\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use LogicException;

class TenantCache
{
    public function __construct(private TenantContext $context) {}

    private function key(string $key): string
    {
        if (! $this->context->hasTenant()) {
            throw new LogicException('Tenant context is required for tenant cache.');
        }

        return 'tenant:'.$this->context->getTenantId().':'.$key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->key($key), $default);
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return Cache::put($this->key($key), $value, $ttl);
    }

    public function forget(string $key): bool
    {
        return Cache::forget($this->key($key));
    }
}
