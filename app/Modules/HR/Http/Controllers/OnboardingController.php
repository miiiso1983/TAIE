<?php

namespace App\Modules\HR\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\OnboardingTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function index(string $employeeUuid)
    {
        $employee = Employee::query()->where('uuid', $employeeUuid)->firstOrFail();
        $tasks = OnboardingTask::query()->with(['employee', 'assignee', 'completedBy'])->where('employee_id', $employee->id)->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderBy('created_at')->paginate(50);

        return response()->json(['data' => $tasks->getCollection()->map(fn (OnboardingTask $task) => $this->resource($task)), 'meta' => ['current_page' => $tasks->currentPage(), 'last_page' => $tasks->lastPage()]]);
    }

    public function store(Request $request, string $employeeUuid, AuditTrailService $audit, Outbox $outbox)
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'due_on' => ['nullable', 'date'],
            'assigned_to_user_uuid' => ['nullable', 'uuid'],
        ]);
        $employee = Employee::query()->where('uuid', $employeeUuid)->firstOrFail();
        $assigneeId = isset($data['assigned_to_user_uuid'])
            ? User::query()->where('uuid', $data['assigned_to_user_uuid'])->whereHas('tenants', fn ($query) => $query->whereKey($tenantId)->where('tenant_users.status', 'active'))->value('id')
            : null;
        abort_if(isset($data['assigned_to_user_uuid']) && ! $assigneeId, 422, 'Assignee must be an active member of this tenant.');

        $task = DB::transaction(function () use ($data, $employee, $assigneeId, $audit, $outbox) {
            $task = OnboardingTask::query()->create([
                'uuid' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'assigned_to_user_id' => $assigneeId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'status' => 'pending',
            ]);
            $audit->record('hr.onboarding_task.created', ['module' => 'hr', 'entity_type' => 'onboarding_task', 'entity_id' => $task->uuid, 'new_values' => ['employee_uuid' => $employee->uuid, 'title' => $task->title, 'assigned_to_user_id' => $assigneeId]]);
            $outbox->append('hr.onboarding_task.created', ['task_uuid' => $task->uuid, 'employee_uuid' => $employee->uuid]);

            return $task->load(['employee', 'assignee', 'completedBy']);
        });

        return response()->json(['data' => $this->resource($task)], 201);
    }

    public function update(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'completed', 'waived'])]]);
        $task = DB::transaction(function () use ($uuid, $data, $request, $audit, $outbox) {
            $task = OnboardingTask::query()->with(['employee', 'assignee', 'completedBy'])->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $transitions = ['pending' => ['in_progress', 'completed', 'waived'], 'in_progress' => ['completed', 'waived']];
            abort_unless(in_array($data['status'], $transitions[$task->status] ?? [], true), 409, 'Invalid onboarding task transition.');
            $previous = $task->status;
            $task->forceFill([
                'status' => $data['status'],
                'completed_at' => $data['status'] === 'completed' ? now() : null,
                'completed_by_user_id' => $data['status'] === 'completed' ? $request->user()->getKey() : null,
            ])->save();
            $audit->record('hr.onboarding_task.status_changed', ['module' => 'hr', 'entity_type' => 'onboarding_task', 'entity_id' => $task->uuid, 'old_values' => ['status' => $previous], 'new_values' => ['status' => $task->status]]);
            $outbox->append('hr.onboarding_task.status_changed', ['task_uuid' => $task->uuid, 'status' => $task->status]);

            return $task->load(['employee', 'assignee', 'completedBy']);
        });

        return response()->json(['data' => $this->resource($task)]);
    }

    private function resource(OnboardingTask $task): array
    {
        return [
            'uuid' => $task->uuid,
            'employee_uuid' => $task->employee->uuid,
            'title' => $task->title,
            'description' => $task->description,
            'due_on' => $task->due_on?->toDateString(),
            'status' => $task->status,
            'assigned_to_user_uuid' => $task->assignee?->uuid,
            'completed_by_user_uuid' => $task->completedBy?->uuid,
            'completed_at' => $task->completed_at?->toISOString(),
        ];
    }
}
