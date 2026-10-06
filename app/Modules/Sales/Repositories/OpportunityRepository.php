<?php

namespace App\Modules\Sales\Repositories;

use App\Core\Tenancy\TenantScopedRepository;
use App\Modules\Sales\Models\Opportunity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OpportunityRepository extends TenantScopedRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->query(Opportunity::class)->with(['customer', 'contact', 'pipeline', 'stage'])->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->orderBy('expected_close_on')->paginate(min(max($perPage, 1), 100));
    }

    public function findByUuid(string $uuid): ?Opportunity
    {
        return $this->query(Opportunity::class)->with(['customer', 'contact', 'pipeline', 'stage'])->where('uuid', $uuid)->first();
    }
}
