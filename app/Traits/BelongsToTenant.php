<?php

namespace App\Traits;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if (! $context->hasTenant()) {
                $builder->whereRaw('1 = 0');

                return;
            }
            $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $context->getTenantId());
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            if (! $context->hasTenant()) {
                throw new LogicException('Tenant context is required to create tenant-owned records.');
            }
            if ($model->tenant_id !== null && (int) $model->tenant_id !== $context->getTenantId()) {
                throw new LogicException('Cannot create a record for a different tenant.');
            }
            $model->tenant_id = $context->getTenantId();
        });

        static::saving(function (Model $model): void {
            if (! $model->exists) {
                return;
            }
            $context = app(TenantContext::class);
            if (! $context->hasTenant() || (int) $model->tenant_id !== $context->getTenantId()) {
                throw new LogicException('Tenant context mismatch for tenant-owned record.');
            }
        });

        static::deleting(function (Model $model): void {
            $context = app(TenantContext::class);
            if (! $context->hasTenant() || (int) $model->tenant_id !== $context->getTenantId()) {
                throw new LogicException('Tenant context mismatch for tenant-owned record.');
            }
        });
    }
}
