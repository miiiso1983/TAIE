<?php

namespace Tests\Feature;

use App\Core\Contracts\EmbeddingProviderInterface;
use App\Core\Contracts\LLMProviderInterface;
use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\AI\Models\AIConversation;
use App\Modules\AI\Models\AIMessage;
use App\Modules\AI\Models\KnowledgeChunk;
use App\Modules\AI\Models\KnowledgeDocument;
use App\Modules\AI\Models\KnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversations_are_private_to_user_and_tenant(): void
    {
        [$tenant,$user] = $this->setupUser(['ai.ask']);
        app(TenantContext::class)->setTenant($tenant);
        $conversation = AIConversation::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'status' => 'open']);
        AIMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content_encrypted' => 'My private sales question']);
        $this->getJson('/api/ai/conversations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/ai/conversations/'.$conversation->uuid.'/messages')->assertOk()->assertJsonPath('data.0.content', 'My private sales question');
        $other = User::factory()->create();
        $tenant->users()->attach($other, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::where('tenant_id', $tenant->id)->first();
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $other->id]);
        $this->actingAs($other)->getJson('/api/ai/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/ai/conversations/'.$conversation->uuid.'/messages')->assertNotFound();
        $this->assertNotSame('My private sales question', DB::table('ai_messages')->value('content_encrypted'));
    }

    public function test_followup_reuses_conversation_and_passes_bounded_history_to_provider(): void
    {
        [$tenant,$user] = $this->setupUser(['ai.ask']);
        app(TenantContext::class)->setTenant($tenant);
        $provider = new class implements EmbeddingProviderInterface, LLMProviderInterface
        {
            public array $messages = [];

            public function embed(string $text): array
            {
                return [1, 0];
            }

            public function model(): string
            {
                return 'test-chat-embedding';
            }

            public function complete(array $messages): array
            {
                $this->messages = $messages;

                return ['content' => 'الإجابة من المصدر [1]', 'usage' => ['total_tokens' => 15]];
            }
        };
        app()->instance(EmbeddingProviderInterface::class, $provider);
        app()->instance(LLMProviderInterface::class, $provider);
        $source = KnowledgeSource::create(['uuid' => (string) Str::uuid(), 'name' => 'Company policy', 'sensitivity' => 'internal']);
        $doc = KnowledgeDocument::create(['uuid' => (string) Str::uuid(), 'source_id' => $source->id, 'title' => 'Policy', 'body_encrypted' => 'Internal policy', 'content_hash' => hash('sha256', 'Internal policy'), 'status' => 'indexed']);
        KnowledgeChunk::create(['document_id' => $doc->id, 'chunk_index' => 0, 'content_encrypted' => 'Approved company policy.', 'embedding' => [1, 0], 'embedding_model' => 'test-chat-embedding']);
        $first = $this->postJson('/api/ai/ask', ['question' => 'ما السياسة؟'])->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $payload = ['question' => 'وما خطوات تطبيقها؟', 'conversation_uuid' => $first['conversation_uuid']];
        $this->postJson('/api/ai/ask', $payload)->assertOk()->assertJsonPath('data.conversation_uuid', $first['conversation_uuid']);
        $this->assertSame('ما السياسة؟', $provider->messages[1]['content']);
        $this->assertSame('وما خطوات تطبيقها؟', end($provider->messages)['content']);
        $this->postJson('/api/ai/ask', $payload)->assertOk();
        $this->assertSame(4, DB::table('ai_messages')->count());
    }

    private function setupUser(array $permissions): array
    {
        config(['teami.audit_hmac_key' => str_repeat('p', 48), 'teami.billing_enforce' => false]);
        [$tenant,$user] = $this->tenantUserWithPermissions($permissions);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid)->withHeader('Idempotency-Key', (string) Str::uuid());

        return [$tenant, $user];
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
