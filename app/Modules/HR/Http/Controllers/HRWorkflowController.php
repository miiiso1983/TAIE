<?php

namespace App\Modules\HR\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Application;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\CompensationRecord;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeRelationsCase;
use App\Modules\HR\Models\Goal;
use App\Modules\HR\Models\Interview;
use App\Modules\HR\Models\JobRequisition;
use App\Modules\HR\Models\PerformanceCycle;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\EmployeeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HRWorkflowController extends Controller
{
    public function compensation(string $employeeUuid)
    {
        $employee = Employee::query()->where('uuid', $employeeUuid)->firstOrFail();

        return response()->json(['data' => CompensationRecord::query()->where('employee_id', $employee->id)->orderByDesc('effective_from')->get()->map(fn ($record) => [
            'annual_base' => $record->annual_base_encrypted,
            'currency_code' => $record->currency_code,
            'effective_from' => $record->effective_from?->toDateString(),
            'effective_to' => $record->effective_to?->toDateString(),
        ])]);
    }

    public function createCompensation(Request $request, string $employeeUuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['annual_base' => ['required', 'numeric', 'gt:0', 'max:999999999999'], 'currency_code' => ['required', 'string', 'size:3'], 'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'close_previous' => ['sometimes', 'boolean']]);
        $employee = Employee::query()->where('uuid', $employeeUuid)->firstOrFail();
        $record = DB::transaction(function () use ($request, $data, $employee, $audit, $outbox) {
            // Use the same lock order as advisor review/import and audit, including requests without an idempotency key.
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($data['close_previous'] ?? false) {
                $previous = CompensationRecord::query()->where('employee_id', $employee->id)->whereNull('effective_to')->whereDate('effective_from', '<', $data['effective_from'])->lockForUpdate()->first();
                if ($previous) {
                    $previous->update(['effective_to' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString()]);
                    $audit->record('hr.compensation.period_closed', ['module' => 'hr', 'entity_type' => 'compensation_record', 'entity_id' => $previous->id, 'new_values' => ['effective_to' => $previous->effective_to->toDateString()]]);
                }
            }
            $overlap = CompensationRecord::query()->where('employee_id', $employee->id)
                ->whereDate('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))->exists();
            abort_if($overlap, 422, 'Compensation periods cannot overlap.');
            $record = CompensationRecord::query()->create(['employee_id' => $employee->id, 'annual_base_encrypted' => (string) $data['annual_base'], 'currency_code' => strtoupper($data['currency_code']), 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null, 'approved_by' => $request->user()->getKey()]);
            $audit->record('hr.compensation.created', ['module' => 'hr', 'entity_type' => 'compensation_record', 'entity_id' => $record->id, 'new_values' => ['employee_uuid' => $employee->uuid, 'currency_code' => $record->currency_code, 'effective_from' => $data['effective_from']]]);
            $outbox->append('hr.compensation.created', ['employee_uuid' => $employee->uuid, 'effective_from' => $data['effective_from']]);

            return $record;
        });

        return response()->json(['data' => ['employee_uuid' => $employee->uuid, 'annual_base' => $record->annual_base_encrypted, 'currency_code' => $record->currency_code, 'effective_from' => $record->effective_from->toDateString()]], 201);
    }

    public function attendance(Request $request)
    {
        $filters = $request->validate(['employee_uuid' => ['nullable', 'uuid'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $employeeId = isset($filters['employee_uuid']) ? Employee::query()->where('uuid', $filters['employee_uuid'])->value('id') : null;
        abort_if(isset($filters['employee_uuid']) && ! $employeeId, 404, 'Employee not found.');
        $records = AttendanceRecord::query()->with('employee')->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('work_date', '>=', $from))->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('work_date', '<=', $to))->orderByDesc('work_date')->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $records->getCollection()->map(fn ($record) => ['employee_uuid' => $record->employee->uuid, 'work_date' => $record->work_date->toDateString(), 'shift_code' => $record->shift_code, 'check_in_at' => $record->check_in_at?->toISOString(), 'check_out_at' => $record->check_out_at?->toISOString(), 'status' => $record->status, 'minutes_late' => $record->minutes_late, 'minutes_worked' => $record->minutes_worked]), 'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage()]]);
    }

    public function recordAttendance(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid'], 'work_date' => ['required', 'date'], 'shift_code' => ['sometimes', 'string', 'max:40'], 'check_in_at' => ['nullable', 'date'], 'check_out_at' => ['nullable', 'date', 'after_or_equal:check_in_at'], 'status' => ['sometimes', Rule::in(['present', 'absent', 'late', 'leave', 'holiday'])], 'minutes_late' => ['sometimes', 'integer', 'min:0', 'max:1440'], 'minutes_worked' => ['sometimes', 'integer', 'min:0', 'max:1440'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $record = DB::transaction(function () use ($data, $employee, $request, $audit, $outbox) {
            $record = AttendanceRecord::query()->updateOrCreate(['employee_id' => $employee->id, 'work_date' => $data['work_date'], 'shift_code' => $data['shift_code'] ?? 'default'], ['check_in_at' => $data['check_in_at'] ?? null, 'check_out_at' => $data['check_out_at'] ?? null, 'status' => $data['status'] ?? 'present', 'minutes_late' => $data['minutes_late'] ?? 0, 'minutes_worked' => $data['minutes_worked'] ?? 0, 'notes' => $data['notes'] ?? null, 'recorded_by' => $request->user()->getKey()]);
            $audit->record('hr.attendance.recorded', ['module' => 'hr', 'entity_type' => 'attendance_record', 'entity_id' => $record->id, 'new_values' => ['employee_uuid' => $employee->uuid, 'work_date' => $record->work_date->toDateString(), 'status' => $record->status]]);
            $outbox->append('hr.attendance.recorded', ['employee_uuid' => $employee->uuid, 'work_date' => $record->work_date->toDateString()]);

            return $record;
        });

        return response()->json(['data' => ['employee_uuid' => $employee->uuid, 'work_date' => $record->work_date->toDateString(), 'status' => $record->status]], 201);
    }

    public function cycles()
    {
        return response()->json(['data' => PerformanceCycle::query()->orderByDesc('starts_on')->get(['uuid', 'name', 'starts_on', 'ends_on', 'status'])]);
    }

    public function createCycle(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180', Rule::unique('hr_performance_cycles', 'name')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on']]);
        $cycle = DB::transaction(function () use ($data, $audit, $outbox) {
            $cycle = PerformanceCycle::query()->create(['uuid' => (string) Str::uuid(), ...$data, 'status' => 'active']);
            $audit->record('hr.performance_cycle.created', ['module' => 'hr', 'entity_type' => 'performance_cycle', 'entity_id' => $cycle->uuid, 'new_values' => ['name' => $cycle->name]]);
            $outbox->append('hr.performance_cycle.created', ['cycle_uuid' => $cycle->uuid]);

            return $cycle;
        });

        return response()->json(['data' => ['uuid' => $cycle->uuid, 'name' => $cycle->name, 'status' => $cycle->status]], 201);
    }

    public function transitionCycle(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['completed', 'archived'])]]);
        $cycle = DB::transaction(function () use ($uuid, $data, $audit, $outbox) {
            $cycle = PerformanceCycle::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $previous = $cycle->status;
            $allowed = ['active' => ['completed'], 'completed' => ['archived']];
            abort_unless(in_array($data['status'], $allowed[$previous] ?? [], true), 409, 'Invalid performance cycle transition.');
            if ($data['status'] === 'completed') {
                abort_if($cycle->reviews()->where('status', '!=', 'approved')->exists(), 409, 'All performance reviews must be approved before completing the cycle.');
            }
            $cycle->forceFill(['status' => $data['status']])->save();
            $audit->record('hr.performance_cycle.status_changed', ['module' => 'hr', 'entity_type' => 'performance_cycle', 'entity_id' => $cycle->uuid, 'old_values' => ['status' => $previous], 'new_values' => ['status' => $cycle->status]]);
            $outbox->append('hr.performance_cycle.status_changed', ['cycle_uuid' => $cycle->uuid, 'status' => $cycle->status]);

            return $cycle;
        });

        return response()->json(['data' => ['uuid' => $cycle->uuid, 'status' => $cycle->status]]);
    }

    public function goals(Request $request)
    {
        $filters = $request->validate(['employee_uuid' => ['nullable', 'uuid'], 'cycle_uuid' => ['nullable', 'uuid']]);
        $employeeId = isset($filters['employee_uuid']) ? Employee::query()->where('uuid', $filters['employee_uuid'])->value('id') : null;
        $cycleId = isset($filters['cycle_uuid']) ? PerformanceCycle::query()->where('uuid', $filters['cycle_uuid'])->value('id') : null;
        $goals = Goal::query()->with(['employee', 'cycle'])->when($filters['employee_uuid'] ?? null, fn ($query) => $query->where('employee_id', $employeeId))->when($filters['cycle_uuid'] ?? null, fn ($query) => $query->where('cycle_id', $cycleId))->orderBy('due_on')->get();

        return response()->json(['data' => $goals->map(fn ($goal) => ['uuid' => $goal->uuid, 'title' => $goal->title, 'progress_percent' => $goal->progress_percent, 'status' => $goal->status, 'due_on' => $goal->due_on?->toDateString(), 'employee_uuid' => $goal->employee->uuid, 'cycle_uuid' => $goal->cycle?->uuid])]);
    }

    public function createGoal(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid'], 'cycle_uuid' => ['nullable', 'uuid'], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:4000'], 'weight' => ['sometimes', 'numeric', 'gt:0', 'max:100'], 'due_on' => ['nullable', 'date']]);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $cycleUuid = $data['cycle_uuid'] ?? null;
        $goal = DB::transaction(function () use ($data, $employee, $cycleUuid, $audit, $outbox) {
            $cycle = $cycleUuid ? PerformanceCycle::query()->where('uuid', $cycleUuid)->lockForUpdate()->firstOrFail() : null;
            abort_if($cycle && $cycle->status !== 'active', 409, 'Goals can only be added to an active performance cycle.');
            $goal = Goal::query()->create(['uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'cycle_id' => $cycle?->id, 'title' => $data['title'], 'description' => $data['description'] ?? null, 'weight' => $data['weight'] ?? 100, 'due_on' => $data['due_on'] ?? null]);
            $audit->record('hr.goal.created', ['module' => 'hr', 'entity_type' => 'goal', 'entity_id' => $goal->uuid, 'new_values' => ['employee_uuid' => $employee->uuid, 'title' => $goal->title]]);
            $outbox->append('hr.goal.created', ['goal_uuid' => $goal->uuid]);

            return $goal;
        });

        return response()->json(['data' => ['uuid' => $goal->uuid, 'title' => $goal->title, 'employee_uuid' => $employee->uuid]], 201);
    }

    public function reviews(Request $request)
    {
        $filters = $request->validate(['employee_uuid' => ['nullable', 'uuid'], 'cycle_uuid' => ['nullable', 'uuid']]);
        $employeeId = isset($filters['employee_uuid']) ? Employee::query()->where('uuid', $filters['employee_uuid'])->value('id') : null;
        $cycleId = isset($filters['cycle_uuid']) ? PerformanceCycle::query()->where('uuid', $filters['cycle_uuid'])->value('id') : null;
        $reviews = PerformanceReview::query()->with(['employee', 'cycle', 'reviewer', 'decisionBy'])->when($filters['employee_uuid'] ?? null, fn ($query) => $query->where('employee_id', $employeeId))->when($filters['cycle_uuid'] ?? null, fn ($query) => $query->where('cycle_id', $cycleId))->latest()->paginate(50);

        return response()->json(['data' => $reviews->getCollection()->map(fn ($review) => ['uuid' => $review->uuid, 'employee_uuid' => $review->employee->uuid, 'cycle_uuid' => $review->cycle->uuid, 'reviewer_uuid' => $review->reviewer->uuid, 'score' => $review->score, 'strengths' => $review->strengths, 'development_areas' => $review->development_areas, 'comments' => $review->comments, 'status' => $review->status, 'submitted_at' => $review->submitted_at?->toISOString(), 'decision_notes' => $review->decision_notes, 'decided_at' => $review->decided_at?->toISOString(), 'decision_by_uuid' => $review->decisionBy?->uuid])]);
    }

    public function createReview(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid'], 'cycle_uuid' => ['required', 'uuid'], 'score' => ['nullable', 'numeric', 'between:0,100'], 'strengths' => ['nullable', 'string', 'max:5000'], 'development_areas' => ['nullable', 'string', 'max:5000'], 'comments' => ['nullable', 'string', 'max:5000']]);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $cycleUuid = $data['cycle_uuid'];
        $reviewer = Employee::query()->where('user_id', $request->user()->getKey())->firstOrFail();
        $review = DB::transaction(function () use ($data, $cycleUuid, $employee, $reviewer, $audit, $outbox) {
            $cycle = PerformanceCycle::query()->where('uuid', $cycleUuid)->lockForUpdate()->firstOrFail();
            abort_unless($cycle->status === 'active', 409, 'Reviews can only be submitted for an active performance cycle.');
            abort_if(PerformanceReview::query()->where('cycle_id', $cycle->id)->where('employee_id', $employee->id)->where('reviewer_employee_id', $reviewer->id)->exists(), 409, 'This reviewer already submitted a review for this employee and cycle.');
            $review = PerformanceReview::query()->create(['uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'cycle_id' => $cycle->id, 'reviewer_employee_id' => $reviewer->id, 'score' => $data['score'] ?? null, 'strengths' => $data['strengths'] ?? null, 'development_areas' => $data['development_areas'] ?? null, 'comments' => $data['comments'] ?? null, 'status' => 'submitted', 'submitted_at' => now()]);
            $audit->record('hr.performance_review.submitted', ['module' => 'hr', 'entity_type' => 'performance_review', 'entity_id' => $review->uuid, 'new_values' => ['employee_uuid' => $employee->uuid, 'cycle_uuid' => $cycle->uuid, 'score' => $review->score]]);
            $outbox->append('hr.performance_review.submitted', ['review_uuid' => $review->uuid]);

            return $review;
        });

        return response()->json(['data' => ['uuid' => $review->uuid, 'status' => $review->status]], 201);
    }

    public function decideReview(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'needs_revision'])], 'decision_notes' => ['nullable', 'string', 'max:5000']]);
        $review = DB::transaction(function () use ($uuid, $data, $request, $audit, $outbox) {
            $review = PerformanceReview::query()->with('reviewer')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if((int) $review->reviewer->user_id === (int) $request->user()->getKey(), 403, 'A reviewer cannot approve their own review.');
            abort_unless($review->status === 'submitted', 409, 'Only submitted reviews can be decided.');
            $review->forceFill([
                'status' => $data['status'],
                'decision_by_user_id' => $request->user()->getKey(),
                'decision_notes' => $data['decision_notes'] ?? null,
                'decided_at' => now(),
            ])->save();
            $audit->record('hr.performance_review.decided', ['module' => 'hr', 'entity_type' => 'performance_review', 'entity_id' => $review->uuid, 'new_values' => ['status' => $review->status, 'decision_by_user_id' => $request->user()->getKey()]]);
            $outbox->append('hr.performance_review.decided', ['review_uuid' => $review->uuid, 'status' => $review->status]);

            return $review;
        });

        return response()->json(['data' => ['uuid' => $review->uuid, 'status' => $review->status, 'decided_at' => $review->decided_at?->toISOString()]]);
    }

    public function resubmitReview(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['score' => ['nullable', 'numeric', 'between:0,100'], 'strengths' => ['nullable', 'string', 'max:5000'], 'development_areas' => ['nullable', 'string', 'max:5000'], 'comments' => ['nullable', 'string', 'max:5000']]);
        $review = DB::transaction(function () use ($uuid, $data, $request, $audit, $outbox) {
            $review = PerformanceReview::query()->with('reviewer')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless((int) $review->reviewer->user_id === (int) $request->user()->getKey(), 403, 'Only the original reviewer can revise this review.');
            abort_unless($review->status === 'needs_revision', 409, 'Only reviews returned for revision can be resubmitted.');
            $review->forceFill([...$data, 'status' => 'submitted', 'submitted_at' => now(), 'decision_by_user_id' => null, 'decision_notes' => null, 'decided_at' => null])->save();
            $audit->record('hr.performance_review.resubmitted', ['module' => 'hr', 'entity_type' => 'performance_review', 'entity_id' => $review->uuid, 'new_values' => ['status' => $review->status, 'score' => $review->score]]);
            $outbox->append('hr.performance_review.resubmitted', ['review_uuid' => $review->uuid]);

            return $review;
        });

        return response()->json(['data' => ['uuid' => $review->uuid, 'status' => $review->status]]);
    }

    public function applications(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['applied', 'screening', 'interview', 'offered', 'hired', 'rejected', 'withdrawn'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $applications = Application::query()->with(['candidate', 'requisition'])->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->latest('applied_at')->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $applications->getCollection()->map(fn (Application $application) => ['uuid' => $application->uuid, 'status' => $application->status, 'candidate' => ['uuid' => $application->candidate->uuid, 'first_name' => $application->candidate->first_name, 'last_name' => $application->candidate->last_name, 'email' => $application->candidate->email], 'requisition' => ['uuid' => $application->requisition->uuid, 'title' => $application->requisition->title], 'applied_at' => $application->applied_at?->toISOString()]), 'meta' => ['current_page' => $applications->currentPage(), 'last_page' => $applications->lastPage()]]);
    }

    public function transitionApplication(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['screening', 'interview', 'offered', 'hired', 'rejected', 'withdrawn'])], 'screening_score' => ['nullable', 'numeric', 'between:0,100'], 'decision_notes' => ['nullable', 'string', 'max:5000']]);
        $application = DB::transaction(function () use ($uuid, $data, $audit, $outbox) {
            $application = Application::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $previous = $application->status;
            $allowed = ['applied' => ['screening', 'rejected', 'withdrawn'], 'screening' => ['interview', 'rejected', 'withdrawn'], 'interview' => ['offered', 'rejected', 'withdrawn'], 'offered' => ['hired', 'rejected', 'withdrawn']];
            abort_unless(in_array($data['status'], $allowed[$previous] ?? [], true), 409, 'Invalid application transition.');
            if ($data['status'] === 'offered') {
                abort_unless($application->interviews()->where('status', 'completed')->exists(), 409, 'At least one completed interview is required before making an offer.');
            }
            $application->forceFill(['status' => $data['status'], 'screening_score' => $data['screening_score'] ?? $application->screening_score, 'decision_notes' => $data['decision_notes'] ?? $application->decision_notes])->save();
            $audit->record('hr.application.status_changed', ['module' => 'hr', 'entity_type' => 'application', 'entity_id' => $application->uuid, 'old_values' => ['status' => $previous], 'new_values' => ['status' => $application->status]]);
            $outbox->append('hr.application.status_changed', ['application_uuid' => $application->uuid, 'status' => $application->status]);

            return $application;
        });

        return response()->json(['data' => ['uuid' => $application->uuid, 'status' => $application->status]]);
    }

    public function createEmployeeFromHiredApplication(Request $request, string $uuid, EmployeeService $employees, AuditTrailService $audit, Outbox $outbox)
    {
        $existingApplication = Application::query()->with('employee')->where('uuid', $uuid)->firstOrFail();
        if ($existingApplication->employee) {
            return (new EmployeeResource($existingApplication->employee->load(['department', 'position'])))->response();
        }

        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:60', Rule::unique('hr_employees', 'employee_number')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'work_email' => ['nullable', 'email', 'max:255', Rule::unique('hr_employees', 'work_email')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'hired_at' => ['required', 'date'],
            'manager_uuid' => ['nullable', 'uuid'],
        ]);
        $managerId = isset($data['manager_uuid']) ? Employee::query()->where('uuid', $data['manager_uuid'])->value('id') : null;
        abort_if(isset($data['manager_uuid']) && ! $managerId, 404, 'Manager not found.');

        $result = DB::transaction(function () use ($uuid, $data, $managerId, $employees, $audit, $outbox) {
            $application = Application::query()->with(['candidate', 'requisition'])->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($application->status === 'hired', 409, 'Only hired applications can be converted into employees.');
            if ($application->employee_id) {
                return ['employee' => Employee::query()->whereKey($application->employee_id)->firstOrFail(), 'created' => false];
            }

            $employee = $employees->create([
                'uuid' => (string) Str::uuid(),
                'employee_number' => $data['employee_number'],
                'first_name' => $application->candidate->first_name,
                'last_name' => $application->candidate->last_name,
                'personal_email' => $application->candidate->email,
                'phone' => $application->candidate->phone,
                'work_email' => $data['work_email'] ?? null,
                'department_id' => $application->requisition->department_id,
                'position_id' => $application->requisition->position_id,
                'manager_employee_id' => $managerId,
                'employment_status' => 'active',
                'employment_type' => $application->requisition->employment_type,
                'hired_at' => $data['hired_at'],
            ]);
            $application->forceFill(['employee_id' => $employee->id])->save();
            $audit->record('hr.application.employee_created', ['module' => 'hr', 'entity_type' => 'application', 'entity_id' => $application->uuid, 'new_values' => ['employee_uuid' => $employee->uuid]]);
            $outbox->append('hr.application.employee_created', ['application_uuid' => $application->uuid, 'employee_uuid' => $employee->uuid]);

            return ['employee' => $employee->load(['department', 'position']), 'created' => true];
        });

        return (new EmployeeResource($result['employee']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function interviews(string $applicationUuid)
    {
        $application = Application::query()->where('uuid', $applicationUuid)->firstOrFail();
        $interviews = $application->interviews()->with('interviewer')->orderBy('scheduled_at')->get();

        return response()->json(['data' => $interviews->map(fn (Interview $interview) => ['uuid' => $interview->uuid, 'status' => $interview->status, 'scheduled_at' => $interview->scheduled_at?->toISOString(), 'duration_minutes' => $interview->duration_minutes, 'score' => $interview->score, 'feedback' => $interview->feedback, 'interviewer_uuid' => $interview->interviewer?->uuid])]);
    }

    public function scheduleInterview(Request $request, string $applicationUuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now'], 'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'], 'interviewer_uuid' => ['nullable', 'uuid']]);
        $interviewerId = isset($data['interviewer_uuid']) ? Employee::query()->where('uuid', $data['interviewer_uuid'])->value('id') : null;
        abort_if(isset($data['interviewer_uuid']) && ! $interviewerId, 404, 'Interviewer not found.');

        $interview = DB::transaction(function () use ($applicationUuid, $data, $interviewerId, $audit, $outbox) {
            $application = Application::query()->where('uuid', $applicationUuid)->lockForUpdate()->firstOrFail();
            abort_unless($application->status === 'interview', 409, 'Application must be in interview stage before scheduling an interview.');
            $interview = Interview::query()->create(['uuid' => (string) Str::uuid(), 'application_id' => $application->id, 'interviewer_employee_id' => $interviewerId, 'scheduled_at' => $data['scheduled_at'], 'duration_minutes' => $data['duration_minutes'], 'status' => 'scheduled']);
            $audit->record('hr.interview.scheduled', ['module' => 'hr', 'entity_type' => 'interview', 'entity_id' => $interview->uuid, 'new_values' => ['application_uuid' => $application->uuid, 'scheduled_at' => $interview->scheduled_at?->toISOString()]]);
            $outbox->append('hr.interview.scheduled', ['interview_uuid' => $interview->uuid, 'application_uuid' => $application->uuid]);

            return $interview;
        });

        return response()->json(['data' => ['uuid' => $interview->uuid, 'status' => $interview->status, 'scheduled_at' => $interview->scheduled_at?->toISOString()]], 201);
    }

    public function completeInterview(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['completed', 'cancelled'])], 'score' => ['required_if:status,completed', 'nullable', 'numeric', 'between:0,100'], 'feedback' => ['required_if:status,completed', 'nullable', 'string', 'max:10000']]);
        $interview = DB::transaction(function () use ($uuid, $data, $audit, $outbox) {
            $interview = Interview::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($interview->status === 'scheduled', 409, 'Only scheduled interviews can be completed or cancelled.');
            $interview->forceFill(['status' => $data['status'], 'score' => $data['score'] ?? null, 'feedback' => $data['feedback'] ?? null])->save();
            $audit->record('hr.interview.status_changed', ['module' => 'hr', 'entity_type' => 'interview', 'entity_id' => $interview->uuid, 'new_values' => ['status' => $interview->status, 'score' => $interview->score]]);
            $outbox->append('hr.interview.status_changed', ['interview_uuid' => $interview->uuid, 'status' => $interview->status]);

            return $interview;
        });

        return response()->json(['data' => ['uuid' => $interview->uuid, 'status' => $interview->status, 'score' => $interview->score]]);
    }

    public function requisitions()
    {
        return response()->json(['data' => JobRequisition::query()->with(['department', 'position'])->latest()->paginate(50)->through(fn ($item) => ['uuid' => $item->uuid, 'title' => $item->title, 'status' => $item->status, 'headcount' => $item->headcount, 'department' => $item->department?->name, 'position' => $item->position?->title])]);
    }

    public function createRequisition(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:6000'], 'department_uuid' => ['nullable', 'uuid'], 'position_uuid' => ['nullable', 'uuid'], 'headcount' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contractor', 'temporary'])], 'status' => ['sometimes', Rule::in(['draft', 'open'])]]);
        $department = isset($data['department_uuid']) ? Department::query()->where('uuid', $data['department_uuid'])->firstOrFail() : null;
        $positionId = null;
        if (isset($data['position_uuid'])) {
            $positionId = Position::query()->where('uuid', $data['position_uuid'])->value('id');
            abort_if(! $positionId, 404, 'Position not found.');
        }
        $requisition = DB::transaction(function () use ($data, $department, $positionId, $request, $audit, $outbox) {
            $requisition = JobRequisition::query()->create(['uuid' => (string) Str::uuid(), 'title' => $data['title'], 'description' => $data['description'] ?? null, 'department_id' => $department?->id, 'position_id' => $positionId, 'headcount' => $data['headcount'] ?? 1, 'employment_type' => $data['employment_type'] ?? 'full_time', 'status' => $data['status'] ?? 'draft', 'opened_on' => ($data['status'] ?? 'draft') === 'open' ? today() : null, 'created_by' => $request->user()->getKey()]);
            $audit->record('hr.requisition.created', ['module' => 'hr', 'entity_type' => 'job_requisition', 'entity_id' => $requisition->uuid, 'new_values' => ['title' => $requisition->title, 'status' => $requisition->status]]);
            $outbox->append('hr.requisition.created', ['requisition_uuid' => $requisition->uuid]);

            return $requisition;
        });

        return response()->json(['data' => ['uuid' => $requisition->uuid, 'title' => $requisition->title, 'status' => $requisition->status]], 201);
    }

    public function candidates()
    {
        return response()->json(['data' => Candidate::query()->latest()->paginate(50)->through(fn ($candidate) => ['uuid' => $candidate->uuid, 'first_name' => $candidate->first_name, 'last_name' => $candidate->last_name, 'email' => $candidate->email, 'phone' => $candidate->phone, 'skills' => $candidate->skills_json])]);
    }

    public function createCandidate(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', Rule::unique('hr_candidates', 'email')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'phone' => ['nullable', 'string', 'max:50'], 'skills' => ['sometimes', 'array', 'max:100'], 'skills.*' => ['string', 'max:80'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $candidate = DB::transaction(function () use ($data, $audit, $outbox) {
            $candidate = Candidate::query()->create(['uuid' => (string) Str::uuid(), 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?? null, 'skills_json' => $data['skills'] ?? [], 'notes' => $data['notes'] ?? null]);
            $audit->record('hr.candidate.created', ['module' => 'hr', 'entity_type' => 'candidate', 'entity_id' => $candidate->uuid, 'new_values' => ['email' => $candidate->email]]);
            $outbox->append('hr.candidate.created', ['candidate_uuid' => $candidate->uuid]);

            return $candidate;
        });

        return response()->json(['data' => ['uuid' => $candidate->uuid, 'first_name' => $candidate->first_name, 'last_name' => $candidate->last_name]], 201);
    }

    public function createApplication(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['candidate_uuid' => ['required', 'uuid'], 'requisition_uuid' => ['required', 'uuid']]);
        $candidateId = Candidate::query()->where('uuid', $data['candidate_uuid'])->value('id');
        $requisition = JobRequisition::query()->where('uuid', $data['requisition_uuid'])->where('status', 'open')->firstOrFail();
        abort_if(! $candidateId, 404, 'Candidate not found.');
        $application = DB::transaction(function () use ($candidateId, $requisition, $audit, $outbox) {
            $application = Application::query()->firstOrCreate(['candidate_id' => $candidateId, 'requisition_id' => $requisition->id], ['uuid' => (string) Str::uuid(), 'status' => 'applied', 'applied_at' => now()]);
            if ($application->wasRecentlyCreated) {
                $audit->record('hr.application.created', ['module' => 'hr', 'entity_type' => 'application', 'entity_id' => $application->uuid, 'new_values' => ['requisition_uuid' => $requisition->uuid]]);
                $outbox->append('hr.application.created', ['application_uuid' => $application->uuid]);
            }

            return $application;
        });

        return response()->json(['data' => ['uuid' => $application->uuid, 'status' => $application->status]], $application->wasRecentlyCreated ? 201 : 200);
    }

    public function relationsCases()
    {
        return response()->json(['data' => EmployeeRelationsCase::query()->with('employee')->latest()->paginate(50)->through(fn ($case) => ['uuid' => $case->uuid, 'employee_uuid' => $case->employee->uuid, 'case_type' => $case->case_type, 'severity' => $case->severity, 'status' => $case->status, 'subject' => $case->subject, 'description' => $case->description_encrypted, 'opened_at' => $case->opened_at->toISOString()])]);
    }

    public function updateRelationsCase(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);
        $case = EmployeeRelationsCase::query()->where('uuid', $uuid)->firstOrFail();
        abort_if($case->status === $data['status'], 409, 'Case already has this status.');
        DB::transaction(function () use ($case, $data, $audit, $outbox) {
            $case->forceFill(['status' => $data['status'], 'closed_at' => $data['status'] === 'closed' ? now() : null])->save();
            $audit->record('hr.employee_relations.case_status_changed', ['module' => 'hr', 'entity_type' => 'employee_relations_case', 'entity_id' => $case->uuid, 'new_values' => ['status' => $case->status]]);
            $outbox->append('hr.employee_relations.case_status_changed', ['case_uuid' => $case->uuid, 'status' => $case->status]);
        });

        return response()->json(['data' => ['uuid' => $case->uuid, 'status' => $case->status]]);
    }

    public function createRelationsCase(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid'], 'case_type' => ['required', Rule::in(['grievance', 'conduct', 'accommodation', 'safety', 'other'])], 'severity' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])], 'subject' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:12000']]);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $openedBy = Employee::query()->where('user_id', $request->user()->getKey())->value('id');
        $case = DB::transaction(function () use ($data, $employee, $openedBy, $audit, $outbox) {
            $case = EmployeeRelationsCase::query()->create(['uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'opened_by_employee_id' => $openedBy, 'case_type' => $data['case_type'], 'severity' => $data['severity'] ?? 'medium', 'subject' => $data['subject'], 'description_encrypted' => $data['description']]);
            $audit->record('hr.employee_relations.case_created', ['module' => 'hr', 'entity_type' => 'employee_relations_case', 'entity_id' => $case->uuid, 'new_values' => ['employee_uuid' => $employee->uuid, 'case_type' => $case->case_type, 'severity' => $case->severity]]);
            $outbox->append('hr.employee_relations.case_created', ['case_uuid' => $case->uuid]);

            return $case;
        });

        return response()->json(['data' => ['uuid' => $case->uuid, 'status' => $case->status]], 201);
    }
}
