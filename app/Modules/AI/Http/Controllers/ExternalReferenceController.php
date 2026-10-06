<?php

namespace App\Modules\AI\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\AI\Jobs\IndexKnowledgeDocument;
use App\Modules\AI\Models\KnowledgeDocument;
use App\Modules\AI\Models\KnowledgeSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExternalReferenceController extends Controller
{
    public const ACTIVITIES = ['general' => 'عام', 'pharma' => 'الأدوية والمكاتب العلمية', 'technology' => 'البرمجيات والتكنولوجيا', 'retail' => 'التجارة والتجزئة', 'distribution' => 'التوزيع والخدمات اللوجستية', 'services' => 'الخدمات'];

    private function references(): array
    {
        return json_decode(file_get_contents(resource_path('data/external-references.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function index(TenantContext $context): mixed
    {
        $saved = KnowledgeSource::query()->where('origin', 'external_reference')->get()->keyBy('reference_key');
        $activity = $context->requireTenant()->business_activity;
        $items = array_map(function ($ref) use ($saved, $activity) {
            $source = $saved->get($ref['key']);

            return [...$ref, 'recommended' => in_array('general', $ref['activities'], true) || in_array($activity, $ref['activities'], true), 'source_uuid' => $source?->uuid, 'status' => $source?->status ?? 'not_added'];
        }, $this->references());

        return response()->json(['data' => ['activity' => $activity, 'activities' => collect(self::ACTIVITIES)->map(fn ($name, $key) => ['value' => $key, 'label' => $name])->values(), 'references' => $items]]);
    }

    public function activity(Request $r, TenantContext $context, AuditTrailService $audit): mixed
    {
        $d = $r->validate(['activity' => ['required', Rule::in(array_keys(self::ACTIVITIES))]]);
        DB::transaction(function () use ($d, $context, $audit) {
            $t = Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $t->update(['business_activity' => $d['activity']]);
            $audit->record('ai.reference.activity_changed', ['module' => 'ai', 'entity_type' => 'tenant', 'entity_id' => $t->uuid, 'new_values' => $d]);
        });

        return response()->json(['data' => $d]);
    }

    public function activate(Request $r, string $key, TenantContext $context, AuditTrailService $audit): mixed
    {
        $d = $r->validate(['acknowledge' => ['required', 'accepted'], 'review_note' => ['required', 'string', 'min:12', 'max:1000']]);
        $ref = collect($this->references())->firstWhere('key', $key);
        abort_unless($ref, 404);
        [$source, $document, $dispatch] = DB::transaction(function () use ($ref, $d, $r, $context, $audit) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $metadata = array_intersect_key($ref, array_flip(['url', 'publisher', 'reviewed_at', 'published_at', 'category', 'activities', 'jurisdiction', 'content_type']));
            abort_if(! KnowledgeSource::query()->where('reference_key', $ref['key'])->exists() && KnowledgeSource::query()->where('name', 'مرجع خارجي: '.$ref['title'])->exists(), 409, 'A company source already uses this reserved reference name.');
            $source = KnowledgeSource::query()->firstOrCreate(['reference_key' => $ref['key']], ['uuid' => (string) Str::uuid(), 'name' => 'مرجع خارجي: '.$ref['title'], 'sensitivity' => 'internal', 'created_by' => $r->user()->id, 'origin' => 'external_reference', 'status' => 'active']);
            abort_unless($source->origin === 'external_reference', 409);
            $source->update(['status' => 'active', 'external_metadata' => $metadata, 'external_review_note_encrypted' => $d['review_note']]);
            $body = "مرجع خارجي إرشادي وليس سياسة معتمدة للشركة أو نصاً للقانون العراقي.\nالعنوان: ".$ref['title']."\nالناشر: ".$ref['publisher']."\nالرابط: ".$ref['url']."\nتاريخ مراجعة الملخص: ".$ref['reviewed_at']."\nالمحتوى ملخص تحريري موجز وليس النص الكامل للمصدر.\n".$ref['summary'];
            $document = KnowledgeDocument::query()->firstOrCreate(['source_id' => $source->id, 'content_hash' => hash('sha256', $body)], ['uuid' => (string) Str::uuid(), 'title' => $ref['title'].' — ملخص مرجعي', 'body_encrypted' => $body, 'status' => 'queued', 'created_by' => $r->user()->id]);
            $dispatch = $document->wasRecentlyCreated || $document->status === 'failed';
            if ($document->status === 'failed') {
                $document->update(['status' => 'queued', 'processing_error' => null]);
            }
            $audit->record('ai.reference.activated', ['module' => 'ai', 'entity_type' => 'knowledge_source', 'entity_id' => $source->uuid, 'new_values' => ['reference_key' => $ref['key']]]);

            return [$source, $document, $dispatch];
        });
        if ($dispatch) {
            IndexKnowledgeDocument::dispatch($context->getTenantId(), $document->id)->afterCommit();
        }

        return response()->json(['data' => ['source_uuid' => $source->uuid, 'document_uuid' => $document->uuid, 'status' => $document->status, 'origin' => 'external_reference']], 202);
    }

    public function disable(string $key, TenantContext $context, AuditTrailService $audit): mixed
    {
        DB::transaction(function () use ($key, $context, $audit) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $source = KnowledgeSource::query()->where('reference_key', $key)->where('origin', 'external_reference')->firstOrFail();
            $source->update(['status' => 'disabled']);
            $audit->record('ai.reference.disabled', ['module' => 'ai', 'entity_type' => 'knowledge_source', 'entity_id' => $source->uuid]);
        });

        return response()->json(['data' => ['status' => 'disabled']]);
    }
}
