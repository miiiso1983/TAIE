<?php

namespace App\Console\Commands;

use App\Core\Logging\AuditTrailService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GrantPlatformOwner extends Command
{
    protected $signature = 'teami:platform:owner {email}';

    protected $description = 'Explicitly grant global tenant provisioning to a trusted site owner.';

    public function handle(AuditTrailService $audit): int
    {
        $user = User::query()->where('email', mb_strtolower(trim($this->argument('email'))))->where('status', 'active')->first();
        if (! $user) {
            $this->error('Active account not found.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $audit) {
            $inserted = DB::table('platform_owners')->insertOrIgnore(['user_id' => $user->id, 'granted_at' => now()]);
            if ($inserted) {
                $audit->record('platform.owner.granted', ['module' => 'platform', 'entity_type' => 'user', 'entity_id' => $user->uuid, 'user_id' => $user->id]);
            }
        });
        $this->info('Platform owner access granted. Other tenant owners remain unchanged.');

        return self::SUCCESS;
    }
}
