<?php

namespace App\Modules\AI\Jobs;

use App\Core\Contracts\EmbeddingProviderInterface;
use App\Jobs\TenantAwareJob;
use App\Modules\AI\Models\KnowledgeChunk;
use App\Modules\AI\Models\KnowledgeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class IndexKnowledgeDocument extends TenantAwareJob
{
    public function __construct(int $tenantId, public readonly int $documentId)
    {
        parent::__construct($tenantId);
        $this->onQueue('ai');
    }

    protected function handleForTenant(): void
    {
        $document = KnowledgeDocument::query()->whereKey($this->documentId)->firstOrFail();
        $document->update(['status' => 'indexing', 'processing_error' => null]);
        try {
            $body = trim($document->body_encrypted);
            $size = max(300, (int) config('teami_ai.chunk_size', 1200));
            $overlap = min(max(0, (int) config('teami_ai.chunk_overlap', 150)), $size - 1);
            $chunks = [];
            $offset = 0;
            $length = mb_strlen($body);
            while ($offset < $length) {
                $part = trim(mb_substr($body, $offset, $size));
                if ($part !== '') {
                    $chunks[] = $part;
                }
                $offset += $size - $overlap;
            }
            if ($chunks === []) {
                throw new \RuntimeException('Document contains no indexable text.');
            }
            $provider = app(EmbeddingProviderInterface::class);
            $vectors = array_map(fn (string $chunk) => $provider->embed($chunk), $chunks);
            $dimensions = count($vectors[0]);
            foreach ($vectors as $vector) {
                if (count($vector) !== $dimensions) {
                    throw new \RuntimeException('Embedding dimensions changed within one document.');
                }
            }
            DB::transaction(function () use ($document, $chunks, $vectors, $provider) {
                $document->chunks()->delete();
                foreach ($chunks as $index => $content) {
                    KnowledgeChunk::query()->create(['tenant_id' => $document->tenant_id, 'document_id' => $document->id, 'chunk_index' => $index, 'content_encrypted' => $content, 'embedding' => $vectors[$index], 'embedding_model' => $provider->model()]);
                }
                $document->update(['status' => 'indexed', 'chunk_count' => count($chunks)]);
            });
        } catch (Throwable $exception) {
            $document->update(['status' => 'failed', 'processing_error' => Str::limit($exception->getMessage(), 1000)]);
            throw $exception;
        }
    }
}
