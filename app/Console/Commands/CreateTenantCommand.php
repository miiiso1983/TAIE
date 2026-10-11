<?php

namespace App\Console\Commands;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateTenantCommand extends Command
{
    protected $signature = 'teami:tenant:create {slug} {name} {owner_email} {owner_name}';

    protected $description = 'Create a tenant and its first owner account.';

    public function handle(TenantContext $context, AuditTrailService $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('owner_email')));
        if (User::query()->where('email', $email)->exists()) {
            $this->error('That email address already belongs to an account.');

            return self::FAILURE;
        }
        $password = $this->secret('Owner password (minimum 12 characters, with mixed case, a number, and a symbol)');
        $confirmation = $this->secret('Confirm owner password');
        if ($password !== $confirmation || Validator::make(['password' => $password], ['password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]])->fails()) {
            $this->error('The password confirmation or strength requirements failed.');

            return self::FAILURE;
        }

        $tenant = app(TenantProvisioner::class)->create(['slug' => $this->argument('slug'), 'name' => $this->argument('name'), 'owner_email' => $email, 'owner_name' => $this->argument('owner_name'), 'password' => $password]);

        $this->info('Tenant and owner account were created.');
        $this->line('Tenant UUID: '.$tenant->uuid);
        $this->line('Owner email: '.$email);

        return self::SUCCESS;
    }
}
