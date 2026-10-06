<?php

namespace App\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $tenantId) {}

    final public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant || $tenant->status !== 'active') {
            throw new RuntimeException('Tenant is unavailable for this job.');
        }
        $context = app(TenantContext::class);
        $context->setTenant($tenant);
        try {
            $this->handleForTenant();
        } finally {
            $context->clear();
        }
    }

    abstract protected function handleForTenant(): void;
}
