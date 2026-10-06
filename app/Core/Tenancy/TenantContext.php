<?php

namespace App\Core\Tenancy;

use App\Models\Tenant;
use LogicException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getTenantId(): int
    {
        return $this->requireTenant()->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function requireTenant(): Tenant
    {
        return $this->tenant ?? throw new LogicException('A tenant context is required for this operation.');
    }
}
