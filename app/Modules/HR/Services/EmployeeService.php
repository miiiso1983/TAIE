<?php

namespace App\Modules\HR\Services;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Support\Facades\DB;

class EmployeeService
{
    public function __construct(private AuditTrailService $audit, private Outbox $outbox) {}

    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            if (! empty($data['department_id']) && ! Department::query()->whereKey($data['department_id'])->exists()) {
                abort(422, 'Invalid department.');
            }
            if (! empty($data['position_id'])) {
                $position = Position::query()->whereKey($data['position_id'])->first();
                abort_unless($position && (! isset($data['department_id']) || (int) $position->department_id === (int) $data['department_id']), 422, 'Position does not belong to the selected department.');
            }
            $employee = Employee::query()->create($data);
            $this->audit->record('hr.employee.created', ['module' => 'hr', 'entity_type' => 'employee', 'entity_id' => $employee->uuid, 'new_values' => ['employee_number' => $employee->employee_number, 'employment_status' => $employee->employment_status]]);
            $this->outbox->append('hr.employee.created', ['employee_uuid' => $employee->uuid]);

            return $employee->load(['department', 'position']);
        });
    }

    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $departmentId = array_key_exists('department_id', $data) ? $data['department_id'] : $employee->department_id;
            $positionId = array_key_exists('position_id', $data) ? $data['position_id'] : $employee->position_id;
            if ($positionId !== null) {
                $position = Position::query()->whereKey($positionId)->first();
                abort_unless($position && (int) $position->department_id === (int) $departmentId, 422, 'Position does not belong to the selected department.');
            }
            $old = $employee->only(['employee_number', 'employment_status']);
            if (isset($data['employment_status'])) {
                $data['terminated_at'] = $data['employment_status'] === 'terminated' ? ($employee->terminated_at ?? today()) : null;
            }
            $employee->fill($data)->save();
            $this->audit->record('hr.employee.updated', ['module' => 'hr', 'entity_type' => 'employee', 'entity_id' => $employee->uuid, 'old_values' => $old, 'new_values' => $employee->only(['employee_number', 'employment_status'])]);
            $this->outbox->append('hr.employee.updated', ['employee_uuid' => $employee->uuid]);

            return $employee->load(['department', 'position']);
        });
    }
}
