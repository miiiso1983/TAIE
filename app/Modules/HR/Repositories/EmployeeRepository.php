<?php

namespace App\Modules\HR\Repositories;

use App\Core\Tenancy\TenantScopedRepository;
use App\Modules\HR\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EmployeeRepository extends TenantScopedRepository
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->query(Employee::class)->with(['department', 'position'])->when($filters['status'] ?? null, fn ($query, $status) => $query->where('employment_status', $status))->when($filters['search'] ?? null, function ($query, $search) {
            foreach (preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $term) {
                $query->where(function ($query) use ($term) {
                    $query->where('first_name', 'like', '%'.$term.'%')->orWhere('last_name', 'like', '%'.$term.'%')->orWhere('employee_number', 'like', '%'.$term.'%')->orWhere('work_email', 'like', '%'.$term.'%');
                });
            }
        })->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(min(max($perPage, 1), 100));
    }

    public function findByUuid(string $uuid): ?Employee
    {
        return $this->query(Employee::class)->with(['department', 'position'])->where('uuid', $uuid)->first();
    }
}
