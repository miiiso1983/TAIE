<?php

namespace Tests\Feature;

use App\Core\Contracts\EmbeddingProviderInterface;
use App\Core\Contracts\LLMProviderInterface;
use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\AI\Jobs\IndexKnowledgeDocument;
use App\Modules\AI\Models\KnowledgeChunk;
use App\Modules\AI\Models\KnowledgeSource;
use App\Modules\AI\Services\RagAnswerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ExternalReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['ai.knowledge.read', 'ai.knowledge.manage', 'ai.ask'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
        config(['teami.audit_hmac_key' => str_repeat('i', 48), 'teami.billing_enforce' => false]);
    }

    private function actor(array $permissions = self::PERMISSIONS): array
    {
        $t = Tenant::query()->create(['name' => 'Integration Tenant', 'slug' => Str::random(10), 'status' => 'active']);
        $u = User::factory()->create(['status' => 'active']);
        $t->users()->attach($u, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $t->id, 'name' => 'Connector', 'guard_name' => 'api']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        DB::table('role_user')->insert(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_id' => $role->id]);
        $this->actingAs($u)->withHeaders(['X-Tenant' => $t->uuid]);
        app(TenantContext::class)->setTenant($t);

        return [$t, $u, $role];
    }

    public function test_activity_filters_reference_recommendations_without_activating_sources(): void
    {
        [$t] = $this->actor();
        $this->getJson('/api/knowledge/external-references')->assertOk()->assertJsonCount(14, 'data.references');
        $this->patchJson('/api/knowledge/business-activity', ['activity' => 'pharma'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('pharma', $t->fresh()->business_activity);
        $rows = $this->getJson('/api/knowledge/external-references')->json('data.references');
        $this->assertTrue(collect($rows)->firstWhere('key', 'who-distribution')['recommended']);
        $this->assertFalse(collect($rows)->firstWhere('key', 'owasp-security')['recommended']);
        $this->assertDatabaseCount('knowledge_sources', 0);
    }

    private function activate(): TestResponse
    {
        return $this->postJson('/api/knowledge/external-references/cipd-performance/activate', ['acknowledge' => true, 'review_note' => 'يساعد في تحسين مراجعات أداء الفريق'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_review_creates_only_an_encrypted_summary_and_preserves_source_identity(): void
    {
        $this->actor();
        $this->postJson('/api/knowledge/external-references/cipd-performance/activate', [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $uuid = $this->activate()->assertStatus(202)->assertJsonPath('data.origin', 'external_reference')->json('data.source_uuid');
        $this->activate()->assertJsonPath('data.source_uuid', $uuid);
        $this->assertDatabaseCount('knowledge_sources', 1);
        $this->assertDatabaseCount('knowledge_documents', 1);
        $raw = DB::table('knowledge_sources')->first();
        $this->assertStringNotContainsString('مراجعات', $raw->external_review_note_encrypted);
        $this->assertStringNotContainsString('CIPD', DB::table('knowledge_documents')->value('body_encrypted'));
        Queue::assertPushed(IndexKnowledgeDocument::class, 1);
        $this->postJson('/api/knowledge/sources/'.$uuid.'/documents', ['title' => 'Injected', 'text' => 'Company policy'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/knowledge/external-references/cipd-performance/disable', [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('knowledge_sources', ['uuid' => $uuid, 'status' => 'disabled']);
    }

    public function test_readers_cannot_activate_and_foreign_tenants_have_no_saved_references(): void
    {
        $this->actor();
        $this->activate()->assertStatus(202);
        $this->actor(['ai.knowledge.read']);
        $this->activate()->assertForbidden();
        $this->patchJson('/api/knowledge/business-activity', ['activity' => 'pharma'], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $rows = $this->getJson('/api/knowledge/external-references')->json('data.references');
        $this->assertNull(collect($rows)->firstWhere('key', 'cipd-performance')['source_uuid']);
        $this->assertDatabaseCount('knowledge_sources', 1);
    }

    public function test_rag_citations_label_external_references_and_disabled_source_is_excluded(): void
    {
        [$t, $u] = $this->actor();
        $uuid = $this->activate()->json('data.source_uuid');
        app(TenantContext::class)->setTenant($t);
        $source = KnowledgeSource::query()->where('uuid', $uuid)->firstOrFail();
        $doc = $source->documents()->firstOrFail();
        $doc->update(['status' => 'indexed']);
        KnowledgeChunk::query()->create(['document_id' => $doc->id, 'chunk_index' => 0, 'content_encrypted' => 'تحسين مراجعات الأداء بشكل دوري', 'embedding' => [1, 0], 'embedding_model' => 'test-model']);
        $this->mock(EmbeddingProviderInterface::class, function ($m) {
            $m->shouldReceive('embed')->twice()->andReturn([1, 0]);
            $m->shouldReceive('model')->twice()->andReturn('test-model');
        });
        $this->mock(LLMProviderInterface::class, function ($m) {
            $m->shouldReceive('complete')->once()->withArgs(fn ($messages) => str_contains($messages[0]['content'], 'ليس سياسة شركة') && str_contains($messages[0]['content'], 'قانون عراقي'))->andReturn(['content' => 'اقتراح خارجي [1]', 'usage' => []]);
        });
        $service = app(RagAnswerService::class);
        $answer = $service->answer($u, 'كيف أطور أداء الفريق؟');
        $this->assertSame('external_reference', $answer['citations'][0]['origin']);
        $this->assertSame('CIPD', $answer['citations'][0]['publisher']);
        $this->assertStringStartsWith('https://www.cipd.org/', $answer['citations'][0]['url']);
        $source->update(['status' => 'disabled']);
        $answer = $service->answer($u, 'كيف أطور أداء الفريق؟');
        $this->assertSame([], $answer['citations']);
    }

    public function test_catalog_revisions_require_review_and_expiry_can_be_renewed_without_duplicate_documents(): void
    {
        [$tenant] = $this->actor();
        $this->activate()->assertStatus(202);
        app(TenantContext::class)->setTenant($tenant);
        $source = KnowledgeSource::query()->firstOrFail();
        $metadata = $source->external_metadata;
        $source->update(['external_metadata' => [...$metadata, 'catalog_revision' => str_repeat('0', 64)]]);
        $rows = $this->getJson('/api/knowledge/external-references')->assertOk()->json('data.references');
        $this->assertSame('update_available', collect($rows)->firstWhere('key', 'cipd-performance')['review_status']);
        $this->activate()->assertStatus(202);
        app(TenantContext::class)->setTenant($tenant);
        $source->refresh();
        $source->update(['external_metadata' => [...$source->external_metadata, 'review_due_at' => now()->subDay()->toDateString()]]);
        $rows = $this->getJson('/api/knowledge/external-references')->json('data.references');
        $this->assertSame('review_due', collect($rows)->firstWhere('key', 'cipd-performance')['review_status']);
        $this->activate()->assertStatus(202);
        $rows = $this->getJson('/api/knowledge/external-references')->json('data.references');
        $this->assertSame('current', collect($rows)->firstWhere('key', 'cipd-performance')['review_status']);
        $this->assertDatabaseCount('knowledge_documents', 1);
        Queue::assertPushed(IndexKnowledgeDocument::class, 1);
    }

    public function test_rag_uses_only_current_document_and_excludes_expired_reviews(): void
    {
        [$tenant, $user] = $this->actor();
        $this->activate();
        app(TenantContext::class)->setTenant($tenant);
        $source = KnowledgeSource::query()->firstOrFail();
        $current = $source->documents()->firstOrFail();
        $current->update(['status' => 'indexed']);
        $old = $current->replicate(['uuid', 'content_hash']);
        $old->uuid = (string) Str::uuid();
        $old->content_hash = hash('sha256', 'old summary');
        $old->save();
        foreach ([$old, $current] as $doc) {
            KnowledgeChunk::query()->create(['document_id' => $doc->id, 'chunk_index' => 0, 'content_encrypted' => 'مقطع مراجعة الفريق', 'embedding' => [1, 0], 'embedding_model' => 'test-model']);
        }
        $this->mock(EmbeddingProviderInterface::class, function ($m) {
            $m->shouldReceive('embed')->twice()->andReturn([1, 0]);
            $m->shouldReceive('model')->twice()->andReturn('test-model');
        });
        $this->mock(LLMProviderInterface::class, function ($m) {
            $m->shouldReceive('complete')->once()->andReturn(['content' => 'مرجع للمراجعة [1]', 'usage' => []]);
        });
        $service = app(RagAnswerService::class);
        $answer = $service->answer($user, 'مراجعة الفريق');
        $this->assertCount(1, $answer['citations']);
        $this->assertSame($current->uuid, $answer['citations'][0]['document_uuid']);
        $source->update(['external_metadata' => [...$source->external_metadata, 'review_due_at' => now()->subDay()->toDateString()]]);
        $this->assertSame([], $service->answer($user, 'مراجعة الفريق')['citations']);
        $this->assertDatabaseCount('knowledge_documents', 2);
    }
}
