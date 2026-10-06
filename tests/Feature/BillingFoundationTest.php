<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Billing\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_is_deduplicated_per_tenant_and_metric(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::query()->create(['name' => 'B', 'slug' => 'b']);
        $context = app(TenantContext::class);
        $context->setTenant($tenantA);

        $first = app(BillingService::class)->recordUsage('ai.tokens', 12, 'request-1');
        $replayed = app(BillingService::class)->recordUsage('ai.tokens', 99, 'request-1');
        $this->assertSame($first->id, $replayed->id);
        $this->assertSame(12, $replayed->quantity);

        $context->setTenant($tenantB);
        $otherTenant = app(BillingService::class)->recordUsage('ai.tokens', 5, 'request-1');
        $this->assertNotSame($first->id, $otherTenant->id);
        $this->assertDatabaseCount('usage_records', 2);
    }
}
