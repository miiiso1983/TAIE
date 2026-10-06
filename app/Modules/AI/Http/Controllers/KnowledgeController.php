<?php

namespace App\Modules\AI\Http\Controllers;

use App\Core\Authorization\PolicyEvaluator;
use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\AI\Jobs\IndexKnowledgeDocument;
use App\Modules\AI\Models\AgentProfile;
use App\Modules\AI\Models\KnowledgeDocument;
use App\Modules\AI\Models\KnowledgeSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KnowledgeController extends Controller
{
    public function sources(Request $request, PolicyEvaluator $authorization)
    {
        $sources = KnowledgeSource::query();
        if (! $authorization->allows($request->user(), 'ai.knowledge.sensitive.read', ['sensitivity' => 'restricted', 'sensitive_permission' => 'ai.knowledge.sensitive.read'])) {
            $sources->where('sensitivity', 'internal');
        }

        return response()->json(['data' => $sources->withCount('documents')->orderBy('name')->get(['uuid', 'name', 'status', 'sensitivity', 'origin', 'external_metadata', 'created_at'])]);
    }

    public function createSource(Request $request, PolicyEvaluator $authorization, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180', Rule::unique('knowledge_sources', 'name')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'sensitivity' => ['sometimes', Rule::in(['internal', 'confidential', 'restricted'])]]);
        if (($data['sensitivity'] ?? 'internal') !== 'internal') {
            abort_unless($authorization->allows($request->user(), 'ai.knowledge.sensitive.read'), 403, 'Sensitive knowledge permission required.');
        }
        $source = DB::transaction(function () use ($data, $request, $audit, $outbox) {
            $source = KnowledgeSource::query()->create(['uuid' => (string) Str::uuid(), 'name' => $data['name'], 'sensitivity' => $data['sensitivity'] ?? 'internal', 'created_by' => $request->user()->getKey()]);
            $audit->record('ai.knowledge_source.created', ['module' => 'ai', 'entity_type' => 'knowledge_source', 'entity_id' => $source->uuid]);
            $outbox->append('ai.knowledge_source.created', ['source_uuid' => $source->uuid]);

            return $source;
        });

        return response()->json(['data' => ['uuid' => $source->uuid, 'name' => $source->name, 'sensitivity' => $source->sensitivity]], 201);
    }

    public function createDocument(Request $request, string $sourceUuid, TenantContext $context, PolicyEvaluator $authorization, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'text' => ['required', 'string', 'min:1']]);
        abort_if(mb_strlen($data['text']) > (int) config('teami_ai.max_document_chars', 500000), 422, 'Document is too long.');
        $source = KnowledgeSource::query()->where('uuid', $sourceUuid)->where('status', 'active')->firstOrFail();
        if ($source->sensitivity !== 'internal') {
            abort_unless($authorization->allows($request->user(), 'ai.knowledge.sensitive.read', ['sensitivity' => 'restricted', 'sensitive_permission' => 'ai.knowledge.sensitive.read']), 403, 'Access to this source is not allowed.');
        }
        abort_if($source->origin === 'external_reference', 422, 'External summaries are managed through the reference library. Add company documents to a company source.');
        $hash = hash('sha256', $data['text']);
        $document = DB::transaction(function () use ($data, $source, $hash, $request, $audit, $outbox) {
            $document = KnowledgeDocument::query()->firstOrCreate(
                ['source_id' => $source->id, 'content_hash' => $hash],
                ['uuid' => (string) Str::uuid(), 'title' => $data['title'], 'body_encrypted' => $data['text'], 'status' => 'queued', 'created_by' => $request->user()->getKey()],
            );
            if ($document->wasRecentlyCreated) {
                $audit->record('ai.knowledge_document.created', ['module' => 'ai', 'entity_type' => 'knowledge_document', 'entity_id' => $document->uuid, 'new_values' => ['source_uuid' => $source->uuid, 'title' => $document->title]]);
                $outbox->append('ai.knowledge_document.created', ['document_uuid' => $document->uuid, 'source_uuid' => $source->uuid]);
            }

            return $document;
        });
        $shouldDispatch = $document->wasRecentlyCreated || $document->status === 'failed';
        if ($document->status === 'failed') {
            $document->update(['status' => 'queued', 'processing_error' => null]);
        }
        if ($shouldDispatch) {
            IndexKnowledgeDocument::dispatch($context->getTenantId(), $document->id)->afterCommit();
        }

        return response()->json(['data' => ['uuid' => $document->uuid, 'source_uuid' => $source->uuid, 'status' => $document->status]], $document->wasRecentlyCreated ? 202 : 200);
    }

    public function documents(Request $request, string $sourceUuid, PolicyEvaluator $authorization)
    {
        $source = KnowledgeSource::query()->where('uuid', $sourceUuid)->firstOrFail();
        if ($source->sensitivity !== 'internal') {
            abort_unless($authorization->allows($request->user(), 'ai.knowledge.sensitive.read'), 404, 'Source not found.');
        }

        return response()->json(['data' => KnowledgeDocument::query()->where('source_id', $source->id)->latest()->paginate(25)->through(fn ($d) => ['uuid' => $d->uuid, 'title' => $d->title, 'status' => $d->status, 'chunk_count' => $d->chunk_count, 'processing_error' => $d->processing_error])]);
    }

    public function retry(Request $request, string $uuid, TenantContext $context, PolicyEvaluator $authorization)
    {
        $document = KnowledgeDocument::query()->with('source')->where('uuid', $uuid)->firstOrFail();
        if ($document->source->sensitivity !== 'internal') {
            abort_unless($authorization->allows($request->user(), 'ai.knowledge.sensitive.read'), 404, 'Document not found.');
        }
        DB::transaction(function () use ($document, $context) {
            $locked = KnowledgeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'failed', 409, 'Only failed documents may be retried.');
            $locked->update(['status' => 'queued', 'processing_error' => null]);
            IndexKnowledgeDocument::dispatch($context->getTenantId(), $locked->id)->afterCommit();
        });

        return response()->json(['data' => ['uuid' => $document->uuid, 'status' => 'queued']], 202);
    }

    public function document(Request $request, string $uuid, PolicyEvaluator $authorization)
    {
        $document = KnowledgeDocument::query()->with('source')->where('uuid', $uuid)->firstOrFail();
        if ($document->source->sensitivity !== 'internal') {
            abort_unless($authorization->allows($request->user(), 'ai.knowledge.sensitive.read', ['sensitivity' => 'restricted', 'sensitive_permission' => 'ai.knowledge.sensitive.read']), 404, 'Document not found.');
        }

        return response()->json(['data' => ['uuid' => $document->uuid, 'title' => $document->title, 'source_uuid' => $document->source->uuid, 'status' => $document->status, 'chunk_count' => $document->chunk_count, 'processing_error' => $document->processing_error, 'created_at' => $document->created_at]]);
    }

    public function createAgent(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180', Rule::unique('ai_agent_profiles', 'name')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'instructions' => ['required', 'string', 'max:8000'], 'source_uuids' => ['sometimes', 'array', 'max:100'], 'source_uuids.*' => ['required', 'uuid']]);
        $sources = $data['source_uuids'] ?? null;
        if (is_array($sources) && $sources !== [] && KnowledgeSource::query()->whereIn('uuid', $sources)->count() !== count(array_unique($sources))) {
            abort(422, 'Every allowed source must belong to the active tenant.');
        }
        $agent = AgentProfile::query()->create(['uuid' => (string) Str::uuid(), 'name' => $data['name'], 'system_instructions' => $data['instructions'], 'allowed_source_uuids' => $sources, 'created_by' => $request->user()->getKey()]);

        return response()->json(['data' => ['uuid' => $agent->uuid, 'name' => $agent->name, 'active' => $agent->active]], 201);
    }
}
