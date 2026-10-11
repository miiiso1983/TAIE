<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\AI\Http\Controllers\ExternalReferenceController;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Platform\Models\CompanyTrialRegistration;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PublicTrialController
{
    public const PLAN_CODE = 'self_service_trial_v1';

    public const POLICY_VERSION = '2026-10-11';

    public function catalog()
    {
        $options = fn (array $values) => collect($values)->map(fn ($label, $value) => compact('label', 'value'))->values();

        return response()->json(['data' => [
            'plans' => BillingPlan::query()->where('is_active', true)->where('is_public', true)->whereIn('interval', ['month', 'year'])->orderBy('unit_amount')->get(['uuid', 'name', 'currency', 'unit_amount', 'interval', 'limits']),
            'activities' => $options(ExternalReferenceController::ACTIVITIES), 'countries' => $options(config('teami_signup.countries')),
            'company_sizes' => $options(config('teami_signup.company_sizes')),
            'trial' => ['days' => 4, 'ai_requests_total' => 25, 'users' => 5],
            'registration_enabled' => config('teami_signup.enabled'),
            'ai_available' => filled(config('teami_ai.api_key')) && str_starts_with((string) config('teami_ai.endpoint'), 'https://'),
        ]]);
    }

    public function store(Request $request, TenantProvisioner $provisioner)
    {
        abort_unless(config('teami_signup.enabled'), 503, 'التسجيل غير متاح مؤقتًا.');
        $phone = preg_replace('/[\s().-]/', '', trim((string) $request->input('phone')));
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        $request->merge(['owner_email' => mb_strtolower(trim((string) $request->input('owner_email'))), 'phone' => $phone]);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:255', 'legal_name' => 'required|string|min:2|max:255',
            'business_activity' => ['required', Rule::in(array_keys(ExternalReferenceController::ACTIVITIES))],
            'country_code' => ['required', Rule::in(array_keys(config('teami_signup.countries')))],
            'city' => 'required|string|min:2|max:100', 'address' => 'required|string|min:5|max:500',
            'company_size' => ['required', Rule::in(array_keys(config('teami_signup.company_sizes')))],
            'website' => 'nullable|url:http,https|max:255', 'owner_name' => 'required|string|min:2|max:255',
            'job_title' => 'required|string|min:2|max:120', 'owner_email' => 'required|email|max:255',
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'consent' => 'required|accepted', 'preferred_plan_uuid' => ['nullable', 'uuid', Rule::exists('billing_plans', 'uuid')->where('is_public', true)->where('is_active', true)->whereIn('interval', ['month', 'year'])],
            'contact_code' => 'prohibited',
        ]);
        unset($data['contact_code']);
        $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => 'required|uuid'])->validate()['key'];
        $hash = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $phoneHash = hash_hmac('sha256', $phone, (string) config('app.key'));

        return DB::transaction(function () use ($data, $key, $hash, $phoneHash, $provisioner) {
            // One shared plan row serializes registration claims before uniqueness checks.
            $plan = BillingPlan::query()->where('code', self::PLAN_CODE)->lockForUpdate()->firstOrFail();
            abort_unless($plan->is_active, 503, 'التجربة المجانية غير متاحة مؤقتًا.');
            $previous = CompanyTrialRegistration::query()->where('request_key', $key)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->payload_hash, $hash), 409, 'أُعيد استخدام مفتاح الطلب ببيانات مختلفة.');
                $user = User::query()->find($previous->user_id);
                abort_unless($user && $user->status === 'active' && Hash::check($data['password'], $user->password), 409, 'تم إنشاء الحساب سابقًا. انتقل إلى تسجيل الدخول.');

                return response()->json(['data' => $previous->result]);
            }
            Validator::make($data, ['owner_email' => 'unique:users,email'])->validate();
            abort_if(CompanyTrialRegistration::query()->where('phone_fingerprint', $phoneHash)->exists(), 422, 'التجربة متاحة مرة واحدة لكل رقم اتصال. استخدم تسجيل الدخول للحساب السابق.');
            $tenant = $provisioner->create([...$data, 'slug' => 'trial-'.Str::lower(Str::random(12))]);
            $tenant->update(['legal_name' => $data['legal_name'], 'country_code' => $data['country_code'], 'timezone' => 'Asia/Baghdad', 'default_language' => 'ar', 'currency_code' => $data['country_code'] === 'IQ' ? 'IQD' : 'USD', 'business_activity' => $data['business_activity']]);
            $user = $tenant->users()->firstOrFail();
            $context = app(TenantContext::class);
            $previousTenant = $context->hasTenant() ? $context->getTenant() : null;
            $context->setTenant($tenant);
            try {
                $start = now();
                $end = $start->copy()->addDays(4);
                $subscription = TenantSubscription::query()->create(['uuid' => (string) Str::uuid(), 'billing_plan_id' => $plan->id, 'status' => 'trialing', 'starts_at' => $start, 'ends_at' => $end, 'trial_ends_at' => $end, 'external_provider' => 'public_trial']);
                $result = ['tenant' => ['uuid' => $tenant->uuid, 'slug' => $tenant->slug, 'name' => $tenant->name], 'trial' => ['starts_at' => $start->toISOString(), 'ends_at' => $end->toISOString(), 'ai_requests_total' => 25, 'users' => 5]];
                $profile = array_diff_key($data, array_flip(['password', 'consent']));
                $profile['email_verified'] = false;
                CompanyTrialRegistration::query()->create([
                    'request_key' => $key, 'payload_hash' => $hash, 'phone_fingerprint' => $phoneHash,
                    'tenant_id' => $tenant->id, 'user_id' => $user->id, 'profile_encrypted' => $profile,
                    'result' => $result, 'policy_version' => self::POLICY_VERSION, 'consented_at' => now(),
                ]);
                app(AuditTrailService::class)->record('trial.registered', ['module' => 'platform', 'entity_type' => 'subscription', 'entity_id' => $subscription->uuid, 'user_id' => $user->id, 'new_values' => ['days' => 4, 'ai_requests_total' => 25, 'policy_version' => self::POLICY_VERSION]]);

                return response()->json(['data' => $result], 201);
            } finally {
                $context->clear();
                if ($previousTenant) {
                    $context->setTenant($previousTenant);
                }
            }
        });
    }

    public function registration(string $uuid)
    {
        $tenant = Tenant::query()->where('uuid', $uuid)->firstOrFail();
        $registration = CompanyTrialRegistration::query()->where('tenant_id', $tenant->id)->first();

        return response()->json(['data' => $registration ? ['profile' => $registration->profile_encrypted, 'consented_at' => $registration->consented_at->toISOString(), 'policy_version' => $registration->policy_version] : null]);
    }
}
