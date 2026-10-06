<?php

namespace App\Modules\HR\Services;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LeaveWorkflowService
{
    public function __construct(private TenantContext $context, private AuditTrailService $audit, private Outbox $outbox) {}

    public function submit(Employee $employee, int $leaveTypeId, string $startsOn, string $endsOn, string $requestedDays, ?string $reason = null): LeaveRequest
    {
        return DB::transaction(function () use ($employee, $leaveTypeId, $startsOn, $endsOn, $requestedDays, $reason) {
            abort_if($endsOn < $startsOn, 422, 'Leave end date must not precede the start date.');
            abort_if(substr($startsOn, 0, 4) !== substr($endsOn, 0, 4), 422, 'A leave request cannot cross calendar years.');
            $days = (float) $requestedDays;
            $calendarDays = (new \DateTimeImmutable($startsOn))->diff(new \DateTimeImmutable($endsOn))->days + 1;
            abort_if($days > $calendarDays, 422, 'Requested leave days cannot exceed the date range.');
            abort_if($employee->employment_status === 'terminated', 422, 'Terminated employees cannot request leave.');
            $type = LeaveType::query()->whereKey($leaveTypeId)->where('status', 'active')->firstOrFail();
            $balance = LeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $leaveTypeId)->where('year', substr($startsOn, 0, 4))->lockForUpdate()->first();
            abort_unless($balance, 422, 'No leave balance is configured for this employee and year.');
            abort_if(LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->whereDate('starts_on', '<=', $endsOn)->whereDate('ends_on', '>=', $startsOn)->exists(), 422, 'This employee already has a pending or approved leave request for overlapping dates.');
            abort_if((float) $balance->allocated_days - (float) $balance->used_days - (float) $balance->pending_days < $days, 422, 'Insufficient leave balance.');
            $status = $type->requires_approval ? 'pending' : 'approved';
            if ($type->requires_approval) {
                $balance->increment('pending_days', $days);
            } else {
                $balance->increment('used_days', $days);
            }
            $request = LeaveRequest::query()->create(['uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'leave_type_id' => $leaveTypeId, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'requested_days' => $days, 'reason' => $reason, 'status' => $status, 'decided_at' => $type->requires_approval ? null : now(), 'decision_note' => $type->requires_approval ? null : 'Automatically approved by leave policy.']);
            $event = $type->requires_approval ? 'hr.leave.submitted' : 'hr.leave.approved';
            $this->audit->record($event, ['module' => 'hr', 'entity_type' => 'leave_request', 'entity_id' => $request->uuid, 'new_values' => ['employee_id' => $employee->uuid, 'status' => $status, 'requested_days' => $days]]);
            $this->outbox->append($event, ['leave_request_uuid' => $request->uuid]);

            return $request;
        });
    }

    public function decide(LeaveRequest $request, Employee $approver, bool $approve, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $approver, $approve, $note) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new ConflictHttpException('Only pending leave requests can be decided.');
            }
            $balance = LeaveBalance::query()->where('employee_id', $locked->employee_id)->where('leave_type_id', $locked->leave_type_id)->where('year', $locked->starts_on->format('Y'))->lockForUpdate()->firstOrFail();
            $days = (float) $locked->requested_days;
            $balance->pending_days = max(0, (float) $balance->pending_days - $days);
            if ($approve) {
                $balance->used_days = (float) $balance->used_days + $days;
            }
            $balance->save();
            $locked->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->audit->record('hr.leave.'.($approve ? 'approved' : 'rejected'), ['module' => 'hr', 'entity_type' => 'leave_request', 'entity_id' => $locked->uuid, 'new_values' => ['status' => $locked->status, 'approver_uuid' => $approver->uuid]]);
            $this->outbox->append('hr.leave.'.($approve ? 'approved' : 'rejected'), ['leave_request_uuid' => $locked->uuid]);

            return $locked;
        });
    }
}
