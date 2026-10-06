<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;

class SyncOwnerPermissions extends Command
{
    protected $signature = 'teami:permissions:sync-owners';

    protected $description = 'Synchronize tenant-owner permissions, excluding platform operator powers.';

    public function handle(): int
    {
        $ids = Permission::query()->where('guard_name', 'api')->where('name', 'not like', 'platform.%')->pluck('id');
        Role::query()->whereNotNull('tenant_id')->where('name', 'Tenant Owner')->each(fn ($r) => $r->permissions()->sync($ids));
        $this->info('Tenant-owner permissions synchronized.');

        return self::SUCCESS;
    }
}
