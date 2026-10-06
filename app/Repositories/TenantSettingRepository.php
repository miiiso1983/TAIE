<?php

namespace App\Repositories;

use App\Core\Tenancy\TenantScopedRepository;
use App\Models\TenantSetting;

class TenantSettingRepository extends TenantScopedRepository
{
    public function find(int $id): ?TenantSetting
    {
        return $this->query(TenantSetting::class)->find($id);
    }
}
