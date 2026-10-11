<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionController
{
    private const TIMEZONE = 'Asia/Baghdad';

    public function plans()
    {
        return response()->json(['data' => BillingPlan::query()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function createPlan(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:40|regex:/^[a-z0-9_-]+$/',
            'name' => 'required|string|max:120', 'currency' => ['required', Rule::in(['USD', 'IQD'])],
            'unit_amount' => 'required|integer|min:1|max:1000000000000',
            'interval' => ['required', Rule::in(['month', 'year'])],
            'users' => 'required|integer|min:1|max:100000',
            'ai_requests_per_month' => 'required|integer|min:0|max:1000000',
        ]);

        return $this->once($request, 'plan', $data, function () use ($data) {
            Validator::make($data, ['code' => 'unique:billing_plans,code'])->validate();
            $plan = BillingPlan::query()->create([
                'uuid' => (string) Str::uuid(), 'code' => $data['code'], 'name' => $data['name'],
                'currency' => $data['currency'], 'unit_amount' => $data['unit_amount'], 'interval' => $data['interval'],
                'limits' => ['users' => (int) $data['users'], 'ai_requests_per_month' => (int) $data['ai_requests_per_month']],
                'is_active' => true,
            ]);
            app(AuditTrailService::class)->record('platform.plan.created', ['module' => 'platform', 'entity_type' => 'plan', 'entity_id' => $plan->uuid, 'new_values' => $plan->toArray()]);

            return $plan->toArray();
        });
    }

    public function index(Request $request)
    {
        $data = $request->validate(['month' => 'nullable|date_format:Y-m', 'tenant_uuid' => 'nullable|uuid|exists:tenants,uuid']);
        $query = TenantSubscription::withoutGlobalScope('tenant')->with('plan')->join('tenants', 'tenants.id', '=', 'tenant_subscriptions.tenant_id')->select('tenant_subscriptions.*', 'tenants.name as company_name', 'tenants.uuid as company_uuid');
        if (! empty($data['month'])) {
            $start = CarbonImmutable::createFromFormat('!Y-m', $data['month'], self::TIMEZONE)->startOfMonth()->utc();
            $query->where('starts_at', '<', $start->setTimezone(self::TIMEZONE)->addMonth()->utc())
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start));
        }
        if (! empty($data['tenant_uuid'])) {
            $query->where('tenants.uuid', $data['tenant_uuid']);
        }

        return response()->json($query->orderBy('starts_at')->orderBy('tenant_subscriptions.id')->paginate(100)->through(fn ($s) => $this->present($s)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tenant_uuid' => 'required|uuid|exists:tenants,uuid', 'plan_uuid' => 'required|uuid|exists:billing_plans,uuid',
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'reason' => 'required|string|min:3|max:500',
        ]);

        return $this->once($request, 'subscription', $data, function () use ($data) {
            $tenant = Tenant::query()->where('uuid', $data['tenant_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless($tenant->status === 'active', 409, 'لا يمكن تفعيل اشتراك لشركة غير نشطة.');
            $plan = BillingPlan::query()->where('uuid', $data['plan_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless($plan->is_active, 409, 'الخطة غير متاحة.');
            abort_if($tenant->users()->wherePivot('status', 'active')->count() > ($plan->limits['users'] ?? 0), 409, 'عدد المستخدمين الحالي يتجاوز حد الخطة.');
            $start = CarbonImmutable::parse($data['starts_on'], self::TIMEZONE)->startOfDay()->utc();
            $end = CarbonImmutable::parse($data['ends_on'], self::TIMEZONE)->startOfDay()->addDay()->utc();
            abort_unless($end->isFuture(), 422, 'يجب أن يتضمن الاشتراك يومًا لم ينتهِ بعد.');
            $overlap = TenantSubscription::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
                ->whereIn('status', ['pending', 'active', 'trialing'])->where('starts_at', '<', $end)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))->exists();
            abort_if($overlap, 409, 'توجد فترة اشتراك متداخلة. اختر فترة تجديد بعد انتهاء الاشتراك الحالي.');
            $context = app(TenantContext::class);
            $previous = $context->hasTenant() ? $context->getTenant() : null;
            $context->setTenant($tenant);
            try {
                $subscription = TenantSubscription::query()->create([
                    'uuid' => (string) Str::uuid(), 'billing_plan_id' => $plan->id, 'status' => 'active',
                    'starts_at' => $start, 'ends_at' => $end, 'external_provider' => 'platform_manual',
                ]);
                app(AuditTrailService::class)->record('platform.subscription.granted', [
                    'module' => 'platform', 'entity_type' => 'subscription', 'entity_id' => $subscription->uuid,
                    'new_values' => ['plan_uuid' => $plan->uuid, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'timezone' => self::TIMEZONE, 'reason' => $data['reason']],
                ]);
                $subscription->setAttribute('company_name', $tenant->name);
                $subscription->setAttribute('company_uuid', $tenant->uuid);

                return $this->present($subscription->load('plan'));
            } finally {
                $context->clear();
                if ($previous) {
                    $context->setTenant($previous);
                }
            }
        });
    }

    private function once(Request $request, string $operation, array $data, callable $save)
    {
        $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => 'required|uuid'])->validate()['key'];
        $hash = hash_hmac('sha256', json_encode([$operation, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($request, $key, $hash, $save) {
            abort_unless(DB::table('platform_owners')->where('user_id', $request->user()->id)->lockForUpdate()->first(), 403);
            $previous = DB::table('platform_billing_actions')->where('actor_id', $request->user()->id)->where('request_key', $key)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->payload_hash, $hash), 409, 'Request key already used with different data.');

                return response()->json(['data' => json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR)]);
            }
            $result = $save();
            DB::table('platform_billing_actions')->insert(['actor_id' => $request->user()->id, 'request_key' => $key, 'payload_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return response()->json(['data' => $result], 201);
        });
    }

    private function present(TenantSubscription $subscription): array
    {
        $state = $subscription->status;
        if (in_array($state, ['active', 'trialing'])) {
            $state = $subscription->ends_at && $subscription->ends_at->isPast() ? 'expired' : ($subscription->starts_at->isFuture() ? 'scheduled' : $state);
            if ($state === 'trialing' && (! $subscription->trial_ends_at || $subscription->trial_ends_at->isPast())) {
                $state = 'expired';
            }
        }

        return [
            'uuid' => $subscription->uuid, 'tenant_uuid' => $subscription->company_uuid, 'company' => $subscription->company_name,
            'plan' => $subscription->plan?->name, 'state' => $state,
            'starts_on' => $subscription->starts_at->setTimezone(self::TIMEZONE)->toDateString(),
            'ends_on' => $subscription->ends_at?->subSecond()->setTimezone(self::TIMEZONE)->toDateString(),
            'timezone' => self::TIMEZONE,
        ];
    }
}
