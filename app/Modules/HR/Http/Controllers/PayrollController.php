<?php

namespace App\Modules\HR\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\HR\Models\CompensationRecord;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['draft', 'review', 'approved', 'paid'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $runs = PayrollRun::query()->with(['preparer', 'approver', 'payer', 'returner'])->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->latest('period_starts_on')->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $runs->getCollection()->map(fn (PayrollRun $run) => $this->runResource($run)), 'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage()]]);
    }

    public function show(string $uuid)
    {
        $run = PayrollRun::query()->with(['items.employee', 'preparer', 'approver', 'payer', 'returner'])->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $this->runResource($run) + ['items' => $run->items->map(fn (PayrollItem $item) => $this->itemResource($item))]]);
    }

    public function store(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'period' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3'],
        ]);
        $period = CarbonImmutable::parse($data['period']);
        $startsOn = $period->startOfMonth()->toDateString();
        $endsOn = $period->endOfMonth()->toDateString();
        $currency = strtoupper($data['currency_code']);
        $tenantId = app(TenantContext::class)->getTenantId();

        $run = DB::transaction(function () use ($data, $startsOn, $endsOn, $currency, $tenantId, $request, $audit, $outbox) {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            abort_if(PayrollRun::query()->where('currency_code', $currency)->whereDate('period_starts_on', $startsOn)->exists(), 409, 'A payroll run already exists for this currency and month.');

            $employees = Employee::query()->whereIn('employment_status', ['active', 'on_leave'])
                ->where(fn ($query) => $query->whereNull('hired_at')->orWhereDate('hired_at', '<=', $startsOn))
                ->where(fn ($query) => $query->whereNull('terminated_at')->orWhereDate('terminated_at', '>=', $endsOn))
                ->orderBy('id')->lockForUpdate()->get();
            $employeeIds = $employees->modelKeys();
            $compensation = $employeeIds === [] ? collect() : CompensationRecord::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('currency_code', $currency)
                ->whereDate('effective_from', '<=', $startsOn)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $endsOn))
                ->orderByDesc('effective_from')->get()->unique('employee_id')->keyBy('employee_id');
            abort_if($compensation->isEmpty(), 422, 'No active employees have compensation covering the full payroll month in this currency.');

            $run = PayrollRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $data['name'],
                'period_starts_on' => $startsOn,
                'period_ends_on' => $endsOn,
                'currency_code' => $currency,
                'status' => 'draft',
                'employee_count' => $compensation->count(),
                'excluded_employee_count' => max(0, $employees->count() - $compensation->count()),
                'base_total_encrypted' => '0.00',
                'allowance_total_encrypted' => '0.00',
                'deduction_total_encrypted' => '0.00',
                'net_total_encrypted' => '0.00',
                'prepared_by_user_id' => $request->user()->getKey(),
            ]);

            foreach ($compensation as $employeeId => $record) {
                $baseCents = $this->roundAnnualToMonthlyCents($this->toCents((string) $record->annual_base_encrypted));
                $base = $this->fromCents($baseCents);
                $run->items()->create([
                    'uuid' => (string) Str::uuid(),
                    'employee_id' => $employeeId,
                    'base_pay_encrypted' => $base,
                    'allowances_encrypted' => '0.00',
                    'deductions_encrypted' => '0.00',
                    'net_pay_encrypted' => $base,
                ]);
            }

            $this->recalculateTotals($run);
            $audit->record('hr.payroll_run.created', ['module' => 'hr', 'entity_type' => 'payroll_run', 'entity_id' => $run->uuid, 'new_values' => ['period_starts_on' => $startsOn, 'period_ends_on' => $endsOn, 'currency_code' => $currency, 'employee_count' => $run->employee_count]]);
            $outbox->append('hr.payroll_run.created', ['payroll_run_uuid' => $run->uuid, 'employee_count' => $run->employee_count]);

            return $run;
        });

        return response()->json(['data' => $this->runResource($run)], 201);
    }

    public function updateItem(Request $request, string $runUuid, string $itemUuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate([
            'allowances' => ['sometimes', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'deductions' => ['sometimes', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
        ]);
        abort_if($data === [], 422, 'At least one payroll adjustment is required.');

        $item = DB::transaction(function () use ($runUuid, $itemUuid, $data, $audit, $outbox) {
            $run = PayrollRun::query()->where('uuid', $runUuid)->lockForUpdate()->firstOrFail();
            abort_unless($run->status === 'draft', 409, 'Only draft payroll runs can be edited.');
            $item = PayrollItem::query()->with('employee')->where('payroll_run_id', $run->id)->where('uuid', $itemUuid)->lockForUpdate()->firstOrFail();
            $allowancesCents = array_key_exists('allowances', $data) ? $this->toCents((string) $data['allowances']) : $this->toCents((string) $item->allowances_encrypted);
            $deductionsCents = array_key_exists('deductions', $data) ? $this->toCents((string) $data['deductions']) : $this->toCents((string) $item->deductions_encrypted);
            $baseCents = $this->toCents((string) $item->base_pay_encrypted);
            abort_if($deductionsCents > $baseCents + $allowancesCents, 422, 'Deductions cannot exceed base pay plus allowances.');

            $item->forceFill([
                'allowances_encrypted' => $this->fromCents($allowancesCents),
                'deductions_encrypted' => $this->fromCents($deductionsCents),
                'net_pay_encrypted' => $this->fromCents($baseCents + $allowancesCents - $deductionsCents),
            ])->save();
            $this->recalculateTotals($run);
            $audit->record('hr.payroll_item.adjusted', ['module' => 'hr', 'entity_type' => 'payroll_item', 'entity_id' => $item->uuid, 'new_values' => ['payroll_run_uuid' => $run->uuid, 'employee_uuid' => $item->employee->uuid, 'currency_code' => $run->currency_code]]);
            $outbox->append('hr.payroll_item.adjusted', ['payroll_run_uuid' => $run->uuid, 'payroll_item_uuid' => $item->uuid]);

            return $item->fresh()->load('employee');
        });

        return response()->json(['data' => $this->itemResource($item)]);
    }

    public function submit(string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $run = DB::transaction(function () use ($uuid, $audit, $outbox) {
            $run = PayrollRun::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($run->status === 'draft', 409, 'Only draft payroll runs can be submitted.');
            abort_if($run->items()->count() === 0, 409, 'A payroll run must contain employees before submission.');
            $run->forceFill(['status' => 'review', 'returned_by_user_id' => null, 'return_reason' => null, 'returned_at' => null])->save();
            $audit->record('hr.payroll_run.submitted', ['module' => 'hr', 'entity_type' => 'payroll_run', 'entity_id' => $run->uuid, 'new_values' => ['status' => $run->status, 'employee_count' => $run->employee_count]]);
            $outbox->append('hr.payroll_run.submitted', ['payroll_run_uuid' => $run->uuid]);

            return $run;
        });

        return response()->json(['data' => $this->runResource($run)]);
    }

    public function returnForRevision(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $run = DB::transaction(function () use ($request, $uuid, $data, $audit, $outbox) {
            $run = PayrollRun::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($run->status === 'review', 409, 'Only payroll runs under review can be returned.');
            abort_if((int) $run->prepared_by_user_id === (int) $request->user()->getKey(), 403, 'The payroll preparer cannot return the same run for revision.');
            $run->forceFill(['status' => 'draft', 'returned_by_user_id' => $request->user()->getKey(), 'return_reason' => $data['reason'], 'returned_at' => now()])->save();
            $audit->record('hr.payroll_run.returned', ['module' => 'hr', 'entity_type' => 'payroll_run', 'entity_id' => $run->uuid, 'new_values' => ['status' => $run->status, 'returned_by_user_id' => $request->user()->getKey()]]);
            $outbox->append('hr.payroll_run.returned', ['payroll_run_uuid' => $run->uuid]);

            return $run;
        });

        return response()->json(['data' => $this->runResource($run)]);
    }

    public function approve(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $run = DB::transaction(function () use ($request, $uuid, $audit, $outbox) {
            $run = PayrollRun::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($run->status === 'review', 409, 'Only payroll runs under review can be approved.');
            abort_if((int) $run->prepared_by_user_id === (int) $request->user()->getKey(), 403, 'The payroll preparer cannot approve the same run.');
            $run->forceFill(['status' => 'approved', 'approved_by_user_id' => $request->user()->getKey(), 'approved_at' => now()])->save();
            $audit->record('hr.payroll_run.approved', ['module' => 'hr', 'entity_type' => 'payroll_run', 'entity_id' => $run->uuid, 'new_values' => ['status' => $run->status, 'approved_by_user_id' => $request->user()->getKey()]]);
            $outbox->append('hr.payroll_run.approved', ['payroll_run_uuid' => $run->uuid]);

            return $run;
        });

        return response()->json(['data' => $this->runResource($run)]);
    }

    public function markPaid(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $run = DB::transaction(function () use ($request, $uuid, $audit, $outbox) {
            $run = PayrollRun::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($run->status === 'approved', 409, 'Only approved payroll runs can be marked paid.');
            abort_if((int) $run->approved_by_user_id === (int) $request->user()->getKey(), 403, 'The payroll approver cannot mark the same run paid.');
            $run->forceFill(['status' => 'paid', 'paid_by_user_id' => $request->user()->getKey(), 'paid_at' => now()])->save();
            $audit->record('hr.payroll_run.marked_paid', ['module' => 'hr', 'entity_type' => 'payroll_run', 'entity_id' => $run->uuid, 'new_values' => ['status' => $run->status, 'paid_by_user_id' => $request->user()->getKey()]]);
            $outbox->append('hr.payroll_run.marked_paid', ['payroll_run_uuid' => $run->uuid]);

            return $run;
        });

        return response()->json(['data' => $this->runResource($run)]);
    }

    private function recalculateTotals(PayrollRun $run): void
    {
        $totals = ['base' => 0, 'allowances' => 0, 'deductions' => 0, 'net' => 0];
        foreach ($run->items()->get() as $item) {
            $totals['base'] += $this->toCents((string) $item->base_pay_encrypted);
            $totals['allowances'] += $this->toCents((string) $item->allowances_encrypted);
            $totals['deductions'] += $this->toCents((string) $item->deductions_encrypted);
            $totals['net'] += $this->toCents((string) $item->net_pay_encrypted);
        }
        $run->forceFill([
            'base_total_encrypted' => $this->fromCents($totals['base']),
            'allowance_total_encrypted' => $this->fromCents($totals['allowances']),
            'deduction_total_encrypted' => $this->fromCents($totals['deductions']),
            'net_total_encrypted' => $this->fromCents($totals['net']),
        ])->save();
    }

    private function runResource(PayrollRun $run): array
    {
        $run->loadMissing(['preparer', 'approver', 'payer', 'returner']);

        return [
            'uuid' => $run->uuid,
            'name' => $run->name,
            'period_starts_on' => $run->period_starts_on->toDateString(),
            'period_ends_on' => $run->period_ends_on->toDateString(),
            'currency_code' => $run->currency_code,
            'status' => $run->status,
            'employee_count' => $run->employee_count,
            'excluded_employee_count' => $run->excluded_employee_count,
            'base_total' => $run->base_total_encrypted,
            'allowance_total' => $run->allowance_total_encrypted,
            'deduction_total' => $run->deduction_total_encrypted,
            'net_total' => $run->net_total_encrypted,
            'prepared_by_uuid' => $run->preparer?->uuid,
            'approved_by_uuid' => $run->approver?->uuid,
            'paid_by_uuid' => $run->payer?->uuid,
            'return_reason' => $run->return_reason,
            'returned_by_uuid' => $run->returner?->uuid,
            'returned_at' => $run->returned_at?->toISOString(),
            'approved_at' => $run->approved_at?->toISOString(),
            'paid_at' => $run->paid_at?->toISOString(),
        ];
    }

    private function itemResource(PayrollItem $item): array
    {
        $item->loadMissing('employee');

        return [
            'uuid' => $item->uuid,
            'employee' => ['uuid' => $item->employee->uuid, 'employee_number' => $item->employee->employee_number, 'first_name' => $item->employee->first_name, 'last_name' => $item->employee->last_name],
            'base_pay' => $item->base_pay_encrypted,
            'allowances' => $item->allowances_encrypted,
            'deductions' => $item->deductions_encrypted,
            'net_pay' => $item->net_pay_encrypted,
        ];
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function fromCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function roundAnnualToMonthlyCents(int $annualCents): int
    {
        $monthly = intdiv($annualCents, 12);

        return $monthly + (($annualCents % 12) >= 6 ? 1 : 0);
    }
}
