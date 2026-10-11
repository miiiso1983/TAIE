<?php

namespace Tests\Feature;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\AdvisorReport;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class RestoreRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_cold_restore_preserves_schema_encryption_tenant_scope_and_audit_chain(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite cold-restore rehearsal runs in the dedicated SQLite CI job.');
        }
        $original = DB::getDefaultConnection();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['teami.audit_hmac_key' => str_repeat('r', 48)]);
        $tenant = Tenant::query()->create(['name' => 'Synthetic restore', 'slug' => Str::random(12)]);
        $other = Tenant::query()->create(['name' => 'Other fixture', 'slug' => Str::random(12)]);
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $report = AdvisorReport::query()->create(['uuid' => Str::uuid(), 'kind' => 'sales', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'as_of' => '2026-09-30', 'version' => 1, 'fingerprint' => hash('sha256', 'restore-fixture'), 'snapshot_encrypted' => ['fixture' => 'PRIVATE-RESTORE'], 'created_by' => $user->id]);
        app(AuditTrailService::class)->record('restore.fixture', ['entity_id' => $report->uuid]);
        $backup = tempnam(sys_get_temp_dir(), 'teami-backup-');
        $restored = tempnam(sys_get_temp_dir(), 'teami-restore-');
        try {
            $target = new PDO('sqlite:'.$backup);
            $target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $target->exec('PRAGMA foreign_keys=OFF');
            $source = DB::connection()->getPdo();
            foreach ($source->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC) as $table) {
                $target->exec($table['sql']);
                $quoted = '"'.str_replace('"', '""', $table['name']).'"';
                foreach ($source->query('SELECT * FROM '.$quoted)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $columns = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', array_keys($row)));
                    $stmt = $target->prepare('INSERT INTO '.$quoted.' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                    $stmt->execute(array_values($row));
                }
            }
            foreach ($source->query("SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $sql) {
                $target->exec($sql);
            }
            $stmt = null;
            $target = null;
            $this->assertTrue(copy($backup, $restored));
            $this->assertSame(hash_file('sha256', $backup), hash_file('sha256', $restored));
            config(['database.connections.restore_probe' => array_replace(config('database.connections.sqlite'), ['database' => $restored])]);
            DB::setDefaultConnection('restore_probe');
            $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
            $this->assertCount(0, DB::select('PRAGMA foreign_key_check'));
            $this->assertSame('PRIVATE-RESTORE', AdvisorReport::query()->firstOrFail()->snapshot_encrypted['fixture']);
            $this->assertTrue(app(AuditTrailService::class)->verifyChain($tenant->id));
            config(['teami.audit_hmac_key' => str_repeat('x', 48)]);
            $this->assertFalse(app(AuditTrailService::class)->verifyChain($tenant->id));
            $ciphertext = DB::table('advisor_reports')->value('snapshot_encrypted');
            $this->assertStringNotContainsString('PRIVATE-RESTORE', $ciphertext);
            $rejected = false;
            try {
                (new Encrypter(random_bytes(32), 'AES-256-CBC'))->decrypt($ciphertext);
            } catch (DecryptException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
            app(TenantContext::class)->setTenant($other);
            $this->assertSame(0, AdvisorReport::query()->count());
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('restore_probe');
            $target = null;
            @unlink($backup);
            @unlink($restored);
        }
    }
}
