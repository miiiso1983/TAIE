<?php

namespace App\Modules\AI\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Modules\AI\Jobs\IndexKnowledgeDocument;
use App\Modules\AI\Models\CompanyPolicyDraft;
use App\Modules\AI\Models\KnowledgeDocument;
use App\Modules\AI\Models\KnowledgeSource;
use App\Modules\AI\Services\PolicyTemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PolicyTemplateController
{
    public function catalog(PolicyTemplateCatalog $catalog)
    {
        return response()->json(['data' => $catalog->all()]);
    }

    public function index()
    {
        return response()->json(['data' => CompanyPolicyDraft::query()->latest()->paginate(20)->through(fn ($d) => $this->present($d))]);
    }

    public function store(Request $request, PolicyTemplateCatalog $catalog, AuditTrailService $audit)
    {
        $data = $request->validate(['template_key' => 'required|string|max:60', 'title' => 'required|string|max:255', 'body' => 'required|string|min:20|max:30000']);
        $template = $catalog->find($data['template_key']);
        $draft = CompanyPolicyDraft::query()->create(['uuid' => (string) Str::uuid(), 'template_key' => $template['key'], 'template_version' => $template['version'], 'title' => $data['title'], 'body_encrypted' => $data['body'], 'created_by' => $request->user()->id]);
        $audit->record('ai.policy.draft_created', ['module' => 'ai', 'entity_type' => 'policy_draft', 'entity_id' => $draft->uuid]);

        return response()->json(['data' => $this->present($draft)], 201);
    }

    public function update(Request $request, string $uuid, AuditTrailService $audit)
    {
        $data = $request->validate(['title' => 'required|string|max:255', 'body' => 'required|string|min:20|max:30000']);
        $draft = CompanyPolicyDraft::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
        abort_unless($draft->status === 'draft', 409, 'النسخة المعتمدة لا تعدل؛ أنشئ مسودة جديدة للمراجعة.');
        $draft->update(['title' => $data['title'], 'body_encrypted' => $data['body']]);
        $audit->record('ai.policy.draft_updated', ['module' => 'ai', 'entity_type' => 'policy_draft', 'entity_id' => $draft->uuid]);

        return response()->json(['data' => $this->present($draft)]);
    }

    public function approve(Request $request, string $uuid, TenantContext $context, AuditTrailService $audit)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $draft = CompanyPolicyDraft::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
        if ($draft->status === 'approved') {
            return response()->json(['data' => $this->present($draft)]);
        }
        abort_if(preg_match('/\[[^\]]+\]/u', $draft->body_encrypted), 422, 'أكمل جميع الحقول بين الأقواس قبل الاعتماد.');
        $source = KnowledgeSource::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'سياسة معتمدة: '.$draft->uuid, 'sensitivity' => 'internal', 'created_by' => $request->user()->id]);
        $body = "سياسة اعتمدتها الشركة من قالب TAIE بعد تخصيصه.\n".$draft->body_encrypted;
        $document = KnowledgeDocument::query()->create(['uuid' => (string) Str::uuid(), 'source_id' => $source->id, 'title' => $draft->title, 'body_encrypted' => $body, 'content_hash' => hash('sha256', $body), 'status' => 'queued', 'created_by' => $request->user()->id]);
        $draft->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'knowledge_document_id' => $document->id]);
        $audit->record('ai.policy.approved', ['module' => 'ai', 'entity_type' => 'policy_draft', 'entity_id' => $draft->uuid, 'new_values' => ['document_uuid' => $document->uuid]]);
        IndexKnowledgeDocument::dispatch($context->getTenantId(), $document->id)->afterCommit();

        return response()->json(['data' => $this->present($draft)]);
    }

    private function present(CompanyPolicyDraft $draft): array
    {
        $doc = $draft->knowledge_document_id ? KnowledgeDocument::query()->find($draft->knowledge_document_id) : null;

        return ['uuid' => $draft->uuid, 'template_key' => $draft->template_key, 'template_version' => $draft->template_version, 'title' => $draft->title, 'body' => $draft->body_encrypted, 'status' => $draft->status, 'approved_at' => $draft->approved_at, 'document_uuid' => $doc?->uuid, 'indexing_status' => $doc?->status];
    }
}
