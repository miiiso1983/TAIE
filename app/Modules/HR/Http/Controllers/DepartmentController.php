<?php

namespace App\Modules\HR\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Department::query()->where('status', 'active')->orderBy('name')->get(['uuid', 'code', 'name', 'status'])]);
    }

    public function store(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40', Rule::unique('hr_departments', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'name' => ['required', 'string', 'max:255'], 'parent_uuid' => ['nullable', 'uuid']]);
        $parentId = isset($data['parent_uuid']) ? Department::query()->where('uuid', $data['parent_uuid'])->value('id') : null;
        abort_if(isset($data['parent_uuid']) && ! $parentId, 422, 'Parent department not found.');
        $department = DB::transaction(function () use ($data, $parentId, $audit, $outbox) {
            $department = Department::query()->create(['uuid' => (string) Str::uuid(), 'code' => $data['code'], 'name' => $data['name'], 'parent_department_id' => $parentId]);
            $audit->record('hr.department.created', ['module' => 'hr', 'entity_type' => 'department', 'entity_id' => $department->uuid, 'new_values' => ['code' => $department->code, 'name' => $department->name]]);
            $outbox->append('hr.department.created', ['department_uuid' => $department->uuid]);

            return $department;
        });

        return response()->json(['data' => ['uuid' => $department->uuid, 'code' => $department->code, 'name' => $department->name]], 201);
    }
}
