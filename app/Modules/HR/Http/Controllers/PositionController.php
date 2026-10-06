<?php

namespace App\Modules\HR\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    public function index()
    {
        $positions = Position::query()->with('department')->where('status', 'active')->orderBy('title')->get();

        return response()->json(['data' => $positions->map(fn (Position $position) => ['uuid' => $position->uuid, 'code' => $position->code, 'title' => $position->title, 'grade' => $position->grade, 'employment_type' => $position->employment_type, 'department' => $position->department ? ['uuid' => $position->department->uuid, 'name' => $position->department->name] : null])]);
    }

    public function store(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40', Rule::unique('hr_positions', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'title' => ['required', 'string', 'max:180'], 'department_uuid' => ['nullable', 'uuid'], 'grade' => ['nullable', 'string', 'max:40'], 'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contractor', 'temporary'])], 'description' => ['nullable', 'string', 'max:4000']]);
        $departmentId = isset($data['department_uuid']) ? Department::query()->where('uuid', $data['department_uuid'])->value('id') : null;
        abort_if(isset($data['department_uuid']) && ! $departmentId, 422, 'Department not found.');
        $position = DB::transaction(function () use ($data, $departmentId, $audit, $outbox) {
            $position = Position::query()->create(['uuid' => (string) Str::uuid(), 'code' => $data['code'], 'title' => $data['title'], 'department_id' => $departmentId, 'grade' => $data['grade'] ?? null, 'employment_type' => $data['employment_type'] ?? 'full_time', 'description' => $data['description'] ?? null]);
            $audit->record('hr.position.created', ['module' => 'hr', 'entity_type' => 'position', 'entity_id' => $position->uuid, 'new_values' => ['code' => $position->code, 'title' => $position->title]]);
            $outbox->append('hr.position.created', ['position_uuid' => $position->uuid]);

            return $position;
        });

        return response()->json(['data' => ['uuid' => $position->uuid, 'code' => $position->code, 'title' => $position->title]], 201);
    }
}
