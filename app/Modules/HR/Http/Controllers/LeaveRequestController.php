<?php

namespace App\Modules\HR\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\LeaveWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $requests = LeaveRequest::query()->with(['employee', 'leaveType'])->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->latest('created_at')->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $requests->getCollection()->map(fn (LeaveRequest $leave) => ['uuid' => $leave->uuid, 'status' => $leave->status, 'starts_on' => $leave->starts_on->toDateString(), 'ends_on' => $leave->ends_on->toDateString(), 'requested_days' => $leave->requested_days, 'employee' => ['uuid' => $leave->employee->uuid, 'first_name' => $leave->employee->first_name, 'last_name' => $leave->employee->last_name], 'leave_type' => ['uuid' => $leave->leaveType->uuid, 'name' => $leave->leaveType->name]]), 'meta' => ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total()]]);
    }

    public function store(Request $request, LeaveWorkflowService $workflow, TenantContext $context)
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid'], 'leave_type_uuid' => ['required', 'uuid'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'requested_days' => ['required', 'numeric', 'gt:0', 'max:365'], 'reason' => ['nullable', 'string', 'max:4000']]);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $type = LeaveType::query()->where('uuid', $data['leave_type_uuid'])->where('status', 'active')->firstOrFail();
        $leave = $workflow->submit($employee, $type->id, $data['starts_on'], $data['ends_on'], (string) $data['requested_days'], $data['reason'] ?? null);

        return response()->json(['data' => ['uuid' => $leave->uuid, 'status' => $leave->status, 'starts_on' => $leave->starts_on->toDateString(), 'ends_on' => $leave->ends_on->toDateString(), 'requested_days' => $leave->requested_days]], 201);
    }

    public function decide(Request $request, string $uuid, LeaveWorkflowService $workflow, TenantContext $context)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:2000']]);
        $leave = LeaveRequest::query()->where('uuid', $uuid)->firstOrFail();
        $approver = Employee::query()->where('user_id', $request->user()->getKey())->where('employment_status', 'active')->first();
        abort_unless($approver, 422, 'The approver needs an active employee record linked to their user account.');
        $result = $workflow->decide($leave, $approver, $data['decision'] === 'approve', $data['note'] ?? null);

        return response()->json(['data' => ['uuid' => $result->uuid, 'status' => $result->status, 'decided_at' => $result->decided_at?->toISOString()]]);
    }
}
