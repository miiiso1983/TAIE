<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\AI\Jobs\IndexKnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicyLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function setupUser(array $permissions): array
    {
        config(['teami.audit_hmac_key' => str_repeat('p', 48), 'teami.billing_enforce' => false]);
        [$tenant,$user] = $this->tenantUserWithPermissions($permissions);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid)->withHeader('Idempotency-Key', (string) Str::uuid());

        return [$tenant, $user];
    }

    public function test_draft_is_encrypted_and_not_knowledge_until_reviewed_approval(): void
    {
        [$tenant] = $this->setupUser(['ai.knowledge.read', 'ai.knowledge.manage', 'identity.membership.manage']);
        Queue::fake();
        $this->getJson('/api/knowledge/policy-templates')->assertOk()->assertJsonCount(9, 'data');
        $data = ['template_key' => 'leave', 'title' => 'سياسة إجازات الشركة', 'body' => 'الشركة التجريبية: سياسة مخصصة راجعها المسؤول وفق المتطلبات المحلية.'];
        $r = $this->postJson('/api/knowledge/policy-drafts', $data)->assertCreated()->assertJsonPath('data.status', 'draft');
        $uuid = $r->json('data.uuid');
        $this->assertSame(0, DB::table('knowledge_documents')->count());
        $this->assertNotSame($data['body'], DB::table('company_policy_drafts')->value('body_encrypted'));
        $this->postJson('/api/knowledge/policy-drafts', $data)->assertCreated()->assertJsonPath('data.uuid', $uuid);
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $this->postJson('/api/knowledge/policy-drafts/'.$uuid.'/approve', ['confirmed' => true])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.indexing_status', 'queued');
        $this->postJson('/api/knowledge/policy-drafts/'.$uuid.'/approve', ['confirmed' => true])->assertOk();
        $this->assertSame(1, DB::table('knowledge_documents')->count());
        Queue::assertPushed(IndexKnowledgeDocument::class, 1);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->patchJson('/api/knowledge/policy-drafts/'.$uuid, $data)->assertConflict();
    }

    public function test_placeholders_and_unconfirmed_approval_do_not_create_knowledge(): void
    {
        $this->setupUser(['ai.knowledge.manage', 'identity.membership.manage']);
        $uuid = $this->postJson('/api/knowledge/policy-drafts', ['template_key' => 'leave', 'title' => 'Leave draft', 'body' => 'شركة [اسم الشركة] لديها مسودة تحتاج مراجعة قبل التطبيق.'])->assertCreated()->json('data.uuid');
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $this->postJson('/api/knowledge/policy-drafts/'.$uuid.'/approve', ['confirmed' => true])->assertUnprocessable();
        $this->postJson('/api/knowledge/policy-drafts/'.$uuid.'/approve', ['confirmed' => false])->assertUnprocessable();
        $this->assertSame(0, DB::table('knowledge_documents')->count());
    }

    public function test_editor_cannot_approve_without_management_permission(): void
    {
        $this->setupUser(['ai.knowledge.manage']);
        $uuid = $this->postJson('/api/knowledge/policy-drafts', ['template_key' => 'expenses', 'title' => 'Expense draft', 'body' => 'إجراءات واضحة للمصروفات تراجع وتوافق عليها الإدارة.'])->assertCreated()->json('data.uuid');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/knowledge/policy-drafts/'.$uuid.'/approve', ['confirmed' => true])->assertForbidden();
    }

    public function test_policy_drafts_cannot_be_read_or_approved_from_another_tenant(): void
    {
        [$tenant,$user] = $this->setupUser(['ai.knowledge.manage', 'identity.membership.manage']);
        $uuid = $this->postJson('/api/knowledge/policy-drafts', ['template_key' => 'expenses', 'title' => 'Private', 'body' => 'Private company rules only for the original company.'])->assertCreated()->json('data.uuid');
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-policy']);
        $other->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $other->id, 'role_id' => $role->id, 'user_id' => $user->id]);
        $this->withHeader('X-Tenant', $other->uuid)->getJson('/api/knowledge/policy-drafts')->assertOk()->assertJsonCount(0, 'data.data');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/knowledge/policy-drafts/'.$uuid.'/approve', ['confirmed' => true])->assertNotFound();
    }

    private function tenantUserWithPermissions(array $names): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant', 'slug' => 'tenant']);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Business Operator', 'guard_name' => 'api']);
        $permissions = collect($names)->map(fn ($name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['module' => str_starts_with($name, 'hr.') ? 'hr' : 'sales']));
        $role->permissions()->sync($permissions->pluck('id')->all());
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $user->id]);

        return [$tenant, $user];
    }
}
