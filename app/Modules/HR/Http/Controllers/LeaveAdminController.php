<?php

namespace App\Modules\HR\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeaveAdminController extends Controller
{
    public function indexTypes()
    {
        return response()->json(['data' => LeaveType::query()->orderBy('name')->get(['uuid', 'code', 'name', 'paid', 'requires_approval', 'annual_allowance_days', 'status'])]);
    }

    public function indexBalances(Request $request)
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid'], 'year' => ['required', 'integer', 'between:2000,2200']]);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $balances = LeaveBalance::query()->with('leaveType')->where('employee_id', $employee->id)->where('year', $data['year'])->get();

        return response()->json(['data' => $balances->map(fn (LeaveBalance $balance) => [
            'employee_uuid' => $employee->uuid, 'leave_type_uuid' => $balance->leaveType->uuid,
            'leave_type_name' => $balance->leaveType->name, 'year' => (int) $balance->year,
            'allocated_days' => $balance->allocated_days, 'used_days' => $balance->used_days,
            'pending_days' => $balance->pending_days,
            'available_days' => number_format((float) $balance->allocated_days - (float) $balance->used_days - (float) $balance->pending_days, 2, '.', ''),
        ])]);
    }

    public function createType(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40', Rule::unique('hr_leave_types', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'name' => ['required', 'string', 'max:160'], 'paid' => ['sometimes', 'boolean'], 'requires_approval' => ['sometimes', 'boolean'], 'annual_allowance_days' => ['nullable', 'numeric', 'min:0', 'max:365']]);
        $type = DB::transaction(function () use ($data, $audit, $outbox) {
            $type = LeaveType::query()->create(['uuid' => (string) Str::uuid(), ...$data]);
            $audit->record('hr.leave_type.created', ['module' => 'hr', 'entity_type' => 'leave_type', 'entity_id' => $type->uuid, 'new_values' => ['code' => $type->code]]);
            $outbox->append('hr.leave_type.created', ['leave_type_uuid' => $type->uuid]);

            return $type;
        });

        return response()->json(['data' => ['uuid' => $type->uuid, 'code' => $type->code, 'name' => $type->name]], 201);
    }

    public function setBalance(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['employee_uuid' => ['required', 'uuid'], 'leave_type_uuid' => ['required', 'uuid'], 'year' => ['required', 'integer', 'between:2000,2200'], 'allocated_days' => ['required', 'numeric', 'min:0', 'max:365']]);
        $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $type = LeaveType::query()->where('uuid', $data['leave_type_uuid'])->firstOrFail();
        $balance = DB::transaction(function () use ($data, $employee, $type, $audit, $outbox) {
            $record = LeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $data['year'])->lockForUpdate()->first();
            $old = $record?->allocated_days;
            if (! $record) {
                $record = LeaveBalance::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $data['year'], 'allocated_days' => $data['allocated_days']]);
            } else {
                abort_if((float) $data['allocated_days'] < (float) $record->used_days + (float) $record->pending_days, 422, 'Allocation cannot be lower than used and pending leave.');
                $record->update(['allocated_days' => $data['allocated_days']]);
            }
            $audit->record('hr.leave_balance.set', ['module' => 'hr', 'entity_type' => 'leave_balance', 'entity_id' => $record->id, 'old_values' => ['allocated_days' => $old], 'new_values' => ['allocated_days' => $record->allocated_days, 'year' => $record->year]]);
            $outbox->append('hr.leave_balance.set', ['employee_uuid' => $employee->uuid, 'leave_type_uuid' => $type->uuid, 'year' => $record->year]);

            return $record;
        });

        return response()->json(['data' => ['employee_uuid' => $employee->uuid, 'leave_type_uuid' => $type->uuid, 'year' => $balance->year, 'allocated_days' => $balance->allocated_days, 'used_days' => $balance->used_days, 'pending_days' => $balance->pending_days]]);
    }
}
