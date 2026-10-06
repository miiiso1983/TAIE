<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class TenantScopedRepository
{
    public function __construct(protected TenantContext $tenantContext) {}

    /** @return Builder<Model> */
    protected function query(string $modelClass): Builder
    {
        $tenantId = $this->tenantContext->getTenantId();

        $model = new $modelClass;

        return $modelClass::query()->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
