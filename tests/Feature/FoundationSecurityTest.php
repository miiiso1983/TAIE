<?php

namespace Tests\Feature;

use App\Core\Authorization\PolicyEvaluator;
use App\Core\Events\Outbox;
use App\Core\Events\WebhookInbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Support\TenantCache;
use App\Core\Support\TenantFileService;
use App\Core\Support\TenantStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class FoundationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_keys_are_isolated_and_storage_rejects_traversal(): void
    {
        $a = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $b = Tenant::query()->create(['name' => 'B', 'slug' => 'b']);
        $context = app(TenantContext::class);
        $cacheKey = 'settings-'.bin2hex(random_bytes(8));
        $context->setTenant($a);
        app(TenantCache::class)->put($cacheKey, 'A');
        $this->assertSame('A', app(TenantCache::class)->get($cacheKey));
        $context->setTenant($b);
        $this->assertNull(app(TenantCache::class)->get($cacheKey));
        app(TenantCache::class)->forget($cacheKey);
        $context->setTenant($a);
        app(TenantCache::class)->forget($cacheKey);
        $context->setTenant($b);
        $this->assertStringContainsString('tenants/'.$b->uuid.'/documents/', app(TenantStorage::class)->path('documents'));
        $this->expectException(InvalidArgumentException::class);
        app(TenantStorage::class)->path('documents', '../escape');
    }

    public function test_audit_records_are_chained_redacted_and_append_only(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('k', 48)]);
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $user = User::factory()->create();
        app(TenantContext::class)->setTenant($tenant);
        $this->actingAs($user);
        app(AuditTrailService::class)->record('test.action', ['new_values' => ['password' => 'clear', 'status' => 'active']]);
        $log = AuditLog::query()->firstOrFail();
        $this->assertNull($log->previous_hash);
        $this->assertSame('[REDACTED]', $log->new_values['password']);
        $this->assertNotEmpty($log->current_hash);
        $this->assertTrue(app(AuditTrailService::class)->verifyChain($tenant->id));
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_outbox_event_participates_in_caller_transaction(): void
    {
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        app(TenantContext::class)->setTenant($tenant);
        try {
            DB::transaction(function () {
                app(Outbox::class)->append('test.event', ['value' => 1]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_rbac_permission_is_bound_to_tenant_context(): void
    {
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active']);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'HR Manager', 'guard_name' => 'api']);
        $permission = Permission::query()->create(['name' => 'hr.salary.read', 'guard_name' => 'api']);
        $role->permissions()->attach($permission);
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $user->id]);
        app(TenantContext::class)->setTenant($tenant);
        $this->assertTrue(app(PolicyEvaluator::class)->allows($user, 'hr.salary.read', ['sensitivity' => 'restricted', 'sensitive_permission' => 'hr.salary.read']));
        $this->assertFalse(app(PolicyEvaluator::class)->allows($user, 'hr.salary.read', ['tenant_id' => $tenant->id + 99]));
    }

    public function test_webhook_inbox_verifies_signature_and_deduplicates_events(): void
    {
        $payload = ['id' => 'evt-1', 'secret' => 'should-not-persist'];
        $signature = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'provider-secret');
        $inbox = app(WebhookInbox::class);
        $first = $inbox->receive('provider', 'evt-1', $payload, $signature, 'provider-secret');
        $second = $inbox->receive('provider', 'evt-1', $payload, $signature, 'provider-secret');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('[REDACTED]', $first->fresh()->payload['secret']);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_file_upload_is_private_and_another_tenant_cannot_download_it(): void
    {
        Storage::fake('local');
        $tenantA = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::query()->create(['name' => 'B', 'slug' => 'b']);
        $user = User::factory()->create();
        $tenantA->users()->attach($user, ['status' => 'active']);
        $role = Role::query()->create(['tenant_id' => $tenantA->id, 'name' => 'File Manager', 'guard_name' => 'api']);
        $permissions = collect(['files.create', 'files.read'])->map(fn ($name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['module' => 'files']));
        $role->permissions()->sync($permissions->pluck('id')->all());
        DB::table('role_user')->insert(['tenant_id' => $tenantA->id, 'role_id' => $role->id, 'user_id' => $user->id]);

        $context = app(TenantContext::class);
        $context->setTenant($tenantA);
        $file = app(TenantFileService::class)->store($user, UploadedFile::fake()->createWithContent('report.txt', 'private content'), 'documents', 'internal');
        $this->assertStringStartsWith('tenants/'.$tenantA->uuid.'/documents/', $file->path);
        Storage::disk('local')->assertExists($file->path);

        $context->setTenant($tenantB);
        try {
            app(TenantFileService::class)->download($user, $file->uuid);
            $this->fail('Cross-tenant file download should be denied.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }
}
