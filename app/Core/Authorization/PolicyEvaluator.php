<?php

namespace App\Core\Authorization;

use App\Core\Contracts\AuthorizationInterface;
use App\Core\Tenancy\TenantContext;

class PolicyEvaluator implements AuthorizationInterface
{
    public function __construct(private TenantContext $context) {}

    public function allows(object $user, string $permission, array $attributes = []): bool
    {
        if (! $this->context->hasTenant() || ! method_exists($user, 'hasTenantPermission')) {
            return false;
        }
        if ((int) ($attributes['tenant_id'] ?? $this->context->getTenantId()) !== $this->context->getTenantId()) {
            return false;
        }
        if (! $user->hasTenantPermission($this->context->getTenantId(), $permission)) {
            return false;
        }
        if (($attributes['sensitivity'] ?? null) === 'restricted' && ! $user->hasTenantPermission($this->context->getTenantId(), $attributes['sensitive_permission'] ?? 'data.sensitive.read')) {
            return false;
        }

        return true;
    }
}
