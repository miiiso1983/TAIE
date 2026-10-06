<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\AdvisorReport;
use App\Modules\Advisor\Models\Decision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecisionCenterTest extends TestCase
{
    use RefreshDatabase;

    private const RIGHTS = ['advisor.decision.read', 'advisor.decision.manage', 'advisor.report.read', 'advisor.sales.read', 'data.sensitive.read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['teami.audit_hmac_key' => str_repeat('d', 48), 'teami.billing_enforce' => false]);
    }

    private function actor(array $rights = self::RIGHTS): array
    {
        $t = Tenant::query()->create(['name' => 'Decision Company', 'slug' => Str::random(12)]);
        $u = User::factory()->create(['status' => 'active']);
        $t->users()->attach($u, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $t->id, 'name' => 'Manager', 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $rights)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_id' => $role->id]);
        $this->sign($t, $u);

        return [$t, $u];
    }

    private function sign(Tenant $t, User $u): void
    {
        $this->actingAs($u)->withHeaders(['X-Tenant' => $t->uuid]);
        app(TenantContext::class)->setTenant($t);
    }

    private function payload(User $u): array
    {
        $report = AdvisorReport::query()->create(['uuid' => Str::uuid(), 'kind' => 'sales', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'as_of' => '2026-09-30', 'version' => 1, 'fingerprint' => hash('sha256', 'fixture'), 'snapshot_encrypted' => ['quality' => ['coverage_confirmed' => false]], 'created_by' => $u->id]);

        return ['report_uuid' => $report->uuid, 'title' => 'Private customer followup', 'rationale' => 'Review overdue balances with recorded evidence', 'owner_id' => $u->id, 'due_date' => now()->addWeek()->toDateString(), 'metric' => 'Collected sales', 'unit' => 'USD_minor', 'baseline' => 100, 'target' => 200];
    }

    private function postDecision(array $p)
    {
        return $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/decisions', $p);
    }

    private function move(string $id, int $v, string $s, array $extra = [])
    {
        return $this->withHeader('Idempotency-Key', Str::uuid())->postJson('/api/advisor/decisions/'.$id.'/transition', ['version' => $v, 'status' => $s, 'note' => 'Manager verified this transition', ...$extra]);
    }

    public function test_lifecycle_requires_evidence_and_keeps_encrypted_history_and_versions(): void
    {
        [$t,$u] = $this->actor();
        $id = $this->postDecision($this->payload($u))->assertCreated()->json('data.uuid');
        $this->assertStringNotContainsString('Private customer', DB::table('advisor_decisions')->value('details_encrypted'));
        $this->move($id, 1, 'completed', ['actual' => 150, 'evidence_reference' => 'ERP-verified'])->assertStatus(422);
        $this->move($id, 1, 'accepted')->assertOk()->assertJsonPath('data.version', 2);
        $this->move($id, 1, 'in_progress')->assertStatus(409);
        $this->move($id, 2, 'in_progress')->assertOk();
        $this->move($id, 3, 'completed')->assertStatus(422);
        $this->move($id, 3, 'completed', ['actual' => 150, 'evidence_reference' => 'ERP-verified'])->assertOk()->assertJsonPath('data.details.outcome.delta', 50)->assertJsonPath('data.details.outcome.target_gap', -50);
        $this->move($id, 4, 'accepted')->assertStatus(422);
        $this->getJson('/api/advisor/decisions/'.$id)->assertOk()->assertJsonCount(4, 'data.events');
        $this->assertStringNotContainsString('Manager verified', DB::table('advisor_decision_events')->latest('id')->value('evidence_encrypted'));
    }

    public function test_cross_tenant_report_owner_and_decision_are_rejected(): void
    {
        [$a,$u] = $this->actor();
        $p = $this->payload($u);
        $id = $this->postDecision($p)->assertCreated()->json('data.uuid');
        [$b,$v] = $this->actor();
        $this->getJson('/api/advisor/decisions/'.$id)->assertNotFound();
        $this->move($id, 1, 'accepted')->assertNotFound();
        $this->sign($b, $v);
        $own = $this->payload($v);
        $this->postDecision([...$own, 'owner_id' => $u->id])->assertStatus(422);
        $this->postDecision([...$own, 'report_uuid' => $p['report_uuid']])->assertNotFound();
        $this->getJson('/api/advisor/decisions')->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson('/api/advisor/decisions/owners')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_readonly_permissions_cannot_write_and_hr_reports_need_hr_rights(): void
    {
        [$t,$u] = $this->actor(array_diff(self::RIGHTS, ['advisor.decision.manage']));
        $p = $this->payload($u);
        $this->postDecision($p)->assertForbidden();
        $this->getJson('/api/advisor/decisions/owners')->assertForbidden();
        [$t,$u] = $this->actor();
        $p = $this->payload($u);
        AdvisorReport::query()->where('uuid', $p['report_uuid'])->update(['kind' => 'hr']);
        $this->postDecision($p)->assertForbidden();
    }

    public function test_idempotency_and_upgrade_readiness(): void
    {
        [$t,$u] = $this->actor();
        $p = $this->payload($u);
        $key = Str::uuid();
        $id = $this->withHeader('Idempotency-Key', $key)->postJson('/api/advisor/decisions', $p)->assertCreated()->json('data.uuid');
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/advisor/decisions', $p)->assertCreated()->assertJsonPath('data.uuid', $id);
        $this->sign($t, $u);
        $this->assertSame(1, Decision::query()->count());
        $migration = require database_path('migrations/2026_10_07_000003_create_advisor_decisions.php');
        $migration->down();
        $this->getJson('/api/advisor/decisions')->assertStatus(503);
    }
}
