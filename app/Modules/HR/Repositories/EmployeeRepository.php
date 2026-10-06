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
            $query->where(function ($query) use ($search) {
                $query->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')->orWhere('employee_number', 'like', '%'.$search.'%')->orWhere('work_email', 'like', '%'.$search.'%');
            });
        })->orderBy('last_name')->orderBy('first_name')->paginate(min(max($perPage, 1), 100));
    }

    public function findByUuid(string $uuid): ?Employee
    {
        return $this->query(Employee::class)->with(['department', 'position'])->where('uuid', $uuid)->first();
    }
}
