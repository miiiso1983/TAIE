<?php

namespace App\Modules\AI\Services;

use Carbon\CarbonImmutable;

class ExternalReferenceCatalog
{
    public function all(): array
    {
        return json_decode(file_get_contents(resource_path('data/external-references.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function revision(array $reference): string
    {
        return hash('sha256', json_encode($reference, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function reviewStatus(array $reference, ?array $metadata): string
    {
        if (! $metadata) {
            return 'not_reviewed';
        }
        if (isset($metadata['catalog_revision']) && ! hash_equals($metadata['catalog_revision'], $this->revision($reference))) {
            return 'update_available';
        }

        return $this->expired($metadata) ? 'review_due' : 'current';
    }

    public function expired(array $metadata): bool
    {
        $due = $metadata['review_due_at'] ?? (isset($metadata['reviewed_at']) ? CarbonImmutable::parse($metadata['reviewed_at'])->addMonthsNoOverflow(6)->toDateString() : null);

        return $due === null || CarbonImmutable::parse($due)->endOfDay()->isPast();
    }
}
