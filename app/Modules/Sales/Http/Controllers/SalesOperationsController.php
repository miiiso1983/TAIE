<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\Activity;
use App\Modules\Sales\Models\Contact;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Forecast;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\Target;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SalesOperationsController extends Controller
{
    private const SUBJECT_TYPES = [
        'customer' => Customer::class,
        'contact' => Contact::class,
        'lead' => Lead::class,
        'opportunity' => Opportunity::class,
    ];

    public function activities(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['open', 'completed', 'cancelled'])], 'type' => ['nullable', Rule::in(['call', 'email', 'meeting', 'task', 'note'])]]);
        $activities = Activity::query()->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))->latest('scheduled_at')->paginate(50);

        return response()->json(['data' => $activities->getCollection()->map(fn (Activity $activity) => ['uuid' => $activity->uuid, 'type' => $activity->type, 'subject' => $activity->subject, 'status' => $activity->status, 'scheduled_at' => $activity->scheduled_at?->toISOString(), 'completed_at' => $activity->completed_at?->toISOString(), 'subject_type' => array_search($activity->subjectable_type, self::SUBJECT_TYPES, true)])]);
    }

    public function createActivity(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['call', 'email', 'meeting', 'task', 'note'])], 'subject' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'scheduled_at' => ['nullable', 'date'], 'subject_type' => ['required', Rule::in(array_keys(self::SUBJECT_TYPES))], 'subject_uuid' => ['required', 'uuid'], 'assigned_user_uuid' => ['nullable', 'uuid']]);
        $class = self::SUBJECT_TYPES[$data['subject_type']];
        $subject = $class::query()->where('uuid', $data['subject_uuid'])->firstOrFail();
        $assigneeId = null;
        if (isset($data['assigned_user_uuid'])) {
            $assigneeId = User::query()->where('uuid', $data['assigned_user_uuid'])->whereHas('tenants', fn ($query) => $query->whereKey(app(TenantContext::class)->getTenantId())->wherePivot('status', 'active'))->value('id');
            abort_if(! $assigneeId, 422, 'Assignee must be an active member of this tenant.');
        }
        $activity = DB::transaction(function () use ($data, $subject, $assigneeId, $audit, $outbox) {
            $activity = Activity::query()->create(['uuid' => (string) Str::uuid(), 'type' => $data['type'], 'subject' => $data['subject'], 'description' => $data['description'] ?? null, 'scheduled_at' => $data['scheduled_at'] ?? null, 'status' => 'open', 'assigned_to' => $assigneeId, 'subjectable_type' => $subject::class, 'subjectable_id' => $subject->getKey()]);
            $audit->record('sales.activity.created', ['module' => 'sales', 'entity_type' => 'activity', 'entity_id' => $activity->uuid, 'new_values' => ['type' => $activity->type, 'subject' => $activity->subject]]);
            $outbox->append('sales.activity.created', ['activity_uuid' => $activity->uuid]);

            return $activity;
        });

        return response()->json(['data' => ['uuid' => $activity->uuid, 'type' => $activity->type, 'subject' => $activity->subject, 'status' => $activity->status]], 201);
    }

    public function completeActivity(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['completed', 'cancelled'])]]);
        $activity = Activity::query()->where('uuid', $uuid)->firstOrFail();
        abort_if($activity->status !== 'open', 409, 'Only open activities can be closed.');
        DB::transaction(function () use ($activity, $data, $audit, $outbox) {
            $activity->forceFill(['status' => $data['status'], 'completed_at' => $data['status'] === 'completed' ? now() : null])->save();
            $audit->record('sales.activity.closed', ['module' => 'sales', 'entity_type' => 'activity', 'entity_id' => $activity->uuid, 'new_values' => ['status' => $activity->status]]);
            $outbox->append('sales.activity.closed', ['activity_uuid' => $activity->uuid, 'status' => $activity->status]);
        });

        return response()->json(['data' => ['uuid' => $activity->uuid, 'status' => $activity->status]]);
    }

    public function targets()
    {
        return response()->json(['data' => Target::query()->latest('period_starts_on')->paginate(50)->through(fn (Target $target) => ['uuid' => $target->uuid, 'name' => $target->name, 'metric' => $target->metric, 'target_value' => $target->target_value, 'actual_value' => $target->actual_value, 'currency_code' => $target->currency_code, 'period_starts_on' => $target->period_starts_on->toDateString(), 'period_ends_on' => $target->period_ends_on->toDateString(), 'status' => $target->status])]);
    }

    public function createTarget(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180'], 'metric' => ['required', Rule::in(['revenue', 'calls', 'meetings', 'leads', 'opportunities'])], 'target_value' => ['required', 'numeric', 'gt:0'], 'currency_code' => ['nullable', 'string', 'size:3'], 'period_starts_on' => ['required', 'date'], 'period_ends_on' => ['required', 'date', 'after_or_equal:period_starts_on'], 'owner_user_uuid' => ['nullable', 'uuid']]);
        $ownerId = $request->user()->getKey();
        if (isset($data['owner_user_uuid'])) {
            $ownerId = User::query()->where('uuid', $data['owner_user_uuid'])->whereHas('tenants', fn ($query) => $query->whereKey(app(TenantContext::class)->getTenantId())->wherePivot('status', 'active'))->value('id');
            abort_if(! $ownerId, 422, 'Target owner must be an active tenant member.');
        }
        $target = DB::transaction(function () use ($data, $ownerId, $audit, $outbox) {
            $target = Target::query()->create(['uuid' => (string) Str::uuid(), 'owner_user_id' => $ownerId, 'name' => $data['name'], 'metric' => $data['metric'], 'target_value' => $data['target_value'], 'currency_code' => isset($data['currency_code']) ? strtoupper($data['currency_code']) : null, 'period_starts_on' => $data['period_starts_on'], 'period_ends_on' => $data['period_ends_on'], 'status' => 'active']);
            $audit->record('sales.target.created', ['module' => 'sales', 'entity_type' => 'target', 'entity_id' => $target->uuid, 'new_values' => ['name' => $target->name, 'metric' => $target->metric, 'target_value' => $target->target_value]]);
            $outbox->append('sales.target.created', ['target_uuid' => $target->uuid]);

            return $target;
        });

        return response()->json(['data' => ['uuid' => $target->uuid, 'name' => $target->name, 'target_value' => $target->target_value]], 201);
    }

    public function updateTarget(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['actual_value' => ['required', 'numeric', 'min:0'], 'status' => ['sometimes', Rule::in(['active', 'completed', 'cancelled'])]]);
        $target = Target::query()->where('uuid', $uuid)->firstOrFail();
        DB::transaction(function () use ($target, $data, $audit, $outbox) {
            $target->forceFill(['actual_value' => $data['actual_value'], 'status' => $data['status'] ?? $target->status])->save();
            $audit->record('sales.target.progress_updated', ['module' => 'sales', 'entity_type' => 'target', 'entity_id' => $target->uuid, 'new_values' => ['actual_value' => $target->actual_value, 'status' => $target->status]]);
            $outbox->append('sales.target.progress_updated', ['target_uuid' => $target->uuid]);
        });

        return response()->json(['data' => ['uuid' => $target->uuid, 'actual_value' => $target->actual_value, 'status' => $target->status]]);
    }

    public function forecasts()
    {
        return response()->json(['data' => Forecast::query()->latest('period_starts_on')->paginate(50)->through(fn (Forecast $forecast) => ['uuid' => $forecast->uuid, 'name' => $forecast->name, 'period_starts_on' => $forecast->period_starts_on->toDateString(), 'period_ends_on' => $forecast->period_ends_on->toDateString(), 'currency_code' => $forecast->currency_code, 'pipeline_amount' => $forecast->pipeline_amount, 'weighted_amount' => $forecast->weighted_amount, 'commit_amount' => $forecast->commit_amount, 'status' => $forecast->status, 'generated_at' => $forecast->generated_at?->toISOString()])]);
    }

    public function generateForecast(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180'], 'period_starts_on' => ['required', 'date'], 'period_ends_on' => ['required', 'date', 'after_or_equal:period_starts_on'], 'currency_code' => ['required', 'string', 'size:3']]);
        $currency = strtoupper($data['currency_code']);
        $totals = Opportunity::query()->where('status', 'open')->where('currency_code', $currency)->whereNotNull('expected_close_on')->whereBetween('expected_close_on', [$data['period_starts_on'], $data['period_ends_on']])
            ->selectRaw('COALESCE(SUM(amount), 0) as pipeline_amount, COALESCE(SUM(amount * probability / 100), 0) as weighted_amount, COALESCE(SUM(CASE WHEN probability >= 75 THEN amount ELSE 0 END), 0) as commit_amount')->first();
        $forecast = DB::transaction(function () use ($data, $currency, $totals, $request, $audit, $outbox) {
            $forecast = Forecast::query()->firstOrNew(['name' => $data['name'], 'period_starts_on' => $data['period_starts_on'], 'period_ends_on' => $data['period_ends_on']]);
            $forecast->fill(['currency_code' => $currency, 'pipeline_amount' => $totals->pipeline_amount, 'weighted_amount' => $totals->weighted_amount, 'commit_amount' => $totals->commit_amount, 'status' => 'generated', 'created_by' => $request->user()->getKey(), 'generated_at' => now()]);
            if (! $forecast->exists) {
                $forecast->uuid = (string) Str::uuid();
            }
            $forecast->save();
            $audit->record('sales.forecast.generated', ['module' => 'sales', 'entity_type' => 'forecast', 'entity_id' => $forecast->uuid, 'new_values' => ['currency_code' => $currency, 'pipeline_amount' => $forecast->pipeline_amount, 'weighted_amount' => $forecast->weighted_amount, 'commit_amount' => $forecast->commit_amount]]);
            $outbox->append('sales.forecast.generated', ['forecast_uuid' => $forecast->uuid]);

            return $forecast;
        });

        return response()->json(['data' => ['uuid' => $forecast->uuid, 'currency_code' => $forecast->currency_code, 'pipeline_amount' => $forecast->pipeline_amount, 'weighted_amount' => $forecast->weighted_amount, 'commit_amount' => $forecast->commit_amount, 'generated_at' => $forecast->generated_at?->toISOString()]], 201);
    }
}
