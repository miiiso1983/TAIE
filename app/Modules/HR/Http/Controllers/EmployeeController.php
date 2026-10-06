<?php

namespace App\Modules\HR\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Repositories\EmployeeRepository;
use App\Modules\HR\Services\EmployeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request, EmployeeRepository $employees)
    {
        return EmployeeResource::collection($employees->paginate($request->validate(['status' => ['nullable', Rule::in(['active', 'on_leave', 'terminated'])], 'search' => ['nullable', 'string', 'max:120'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]), (int) $request->integer('per_page', 25)));
    }

    public function show(string $uuid, EmployeeRepository $employees)
    {
        return new EmployeeResource($employees->findByUuid($uuid) ?? abort(404));
    }

    public function store(Request $request, EmployeeService $service)
    {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:60', Rule::unique('hr_employees', 'employee_number')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'],
            'work_email' => ['nullable', 'email', 'max:255'], 'personal_email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'department_uuid' => ['nullable', 'uuid'], 'position_uuid' => ['nullable', 'uuid'], 'manager_uuid' => ['nullable', 'uuid'],
            'employment_status' => ['sometimes', Rule::in(['active', 'on_leave', 'terminated'])], 'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contractor', 'temporary'])],
            'hired_at' => ['nullable', 'date'], 'date_of_birth' => ['nullable', 'date', 'before:today'], 'country_code' => ['nullable', 'string', 'size:2'],
        ]);
        $tenantId = app(TenantContext::class)->getTenantId();
        $data['department_id'] = isset($data['department_uuid']) ? Department::query()->where('uuid', $data['department_uuid'])->value('id') : null;
        abort_if(isset($data['department_uuid']) && ! $data['department_id'], 422, 'Department not found.');
        $data['position_id'] = isset($data['position_uuid']) ? Position::query()->where('uuid', $data['position_uuid'])->value('id') : null;
        abort_if(isset($data['position_uuid']) && ! $data['position_id'], 422, 'Position not found.');
        $data['manager_employee_id'] = isset($data['manager_uuid']) ? Employee::query()->where('uuid', $data['manager_uuid'])->value('id') : null;
        abort_if(isset($data['manager_uuid']) && ! $data['manager_employee_id'], 422, 'Manager not found.');
        unset($data['department_uuid'], $data['position_uuid'], $data['manager_uuid']);
        $data['uuid'] = (string) Str::uuid();

        return (new EmployeeResource($service->create($data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $uuid, EmployeeRepository $employees, EmployeeService $service)
    {
        $employee = $employees->findByUuid($uuid) ?? abort(404);
        $data = $request->validate([
            'employee_number' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('hr_employees', 'employee_number')->where('tenant_id', app(TenantContext::class)->getTenantId())->ignore($employee->id)],
            'first_name' => ['sometimes', 'required', 'string', 'max:120'],
            'last_name' => ['sometimes', 'required', 'string', 'max:120'],
            'work_email' => ['nullable', 'email', 'max:255'],
            'employment_status' => ['sometimes', 'required', Rule::in(['active', 'on_leave', 'terminated'])],
            'employment_type' => ['sometimes', 'required', Rule::in(['full_time', 'part_time', 'contractor', 'temporary'])],
            'hired_at' => ['nullable', 'date'],
            'department_uuid' => ['nullable', 'uuid'],
            'position_uuid' => ['nullable', 'uuid'],
        ]);
        foreach (['department_uuid' => [Department::class, 'department_id'], 'position_uuid' => [Position::class, 'position_id']] as $key => [$model, $column]) {
            if (array_key_exists($key, $data)) {
                $data[$column] = $data[$key] === null ? null : $model::query()->where('uuid', $data[$key])->value('id');
                abort_if($data[$key] !== null && $data[$column] === null, 422, 'Related record not found.');
                unset($data[$key]);
            }
        }

        return new EmployeeResource($service->update($employee, $data));
    }
}
