<?php

namespace App\Http\Controllers\Api;

use App\Core\Authentication\TotpMfaProvider;
use App\Core\Contracts\MFAProviderInterface;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Hash as RecoveryHash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function login(Request $request, TenantContext $context, AuditTrailService $audit, MFAProviderInterface $mfa): JsonResponse
    {
        $request->merge([
            'email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : $request->input('email'),
            'tenant' => is_string($request->input('tenant')) ? (trim($request->input('tenant')) ?: null) : $request->input('tenant'),
        ]);
        $validated = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string', 'tenant' => 'nullable|string|max:255', 'device_name' => 'nullable|string|max:100', 'mfa_code' => 'nullable|string|max:32']);
        $user = User::query()->where('email', mb_strtolower(trim($validated['email'])))->first();
        if (! $user || $user->status !== 'active' || ! Hash::check($validated['password'], $user->password)) {
            abort(401, 'Invalid credentials.');
        }
        if (filled($validated['tenant'] ?? null)) {
            $tenant = Tenant::query()->where('uuid', $validated['tenant'])->orWhere('slug', $validated['tenant'])->first();
        } else {
            $memberships = $user->tenants()->where('tenants.status', 'active')->wherePivot('status', 'active')->limit(2)->get();
            if ($memberships->count() > 1) {
                return response()->json(['success' => false, 'error' => ['code' => 'TENANT_REQUIRED', 'message' => 'حسابك مرتبط بأكثر من شركة. أدخل معرّف الشركة لإكمال الدخول.']], 422);
            }
            $tenant = $memberships->first();
        }
        if (! $tenant || $tenant->status !== 'active' || ! $tenant->users()->whereKey($user->getKey())->wherePivot('status', 'active')->exists()) {
            abort(401, 'Invalid credentials.');
        }
        $mfaEnabled = $user->mfaCredentials()->where('type', 'totp')->whereNotNull('verified_at')->exists();
        if ($mfaEnabled && ! isset($validated['mfa_code'])) {
            return response()->json(['success' => false, 'error' => ['code' => 'MFA_REQUIRED', 'message' => 'A second factor is required.'], 'request_id' => $request->attributes->get('request_id')], 202);
        }
        if ($mfaEnabled && ! $mfa->verify($user->getKey(), $validated['mfa_code'])) {
            abort(401, 'Invalid credentials.');
        }
        $context->setTenant($tenant);
        try {
            $user->forceFill(['last_login_at' => now()])->save();
            $audit->record('auth.login', ['module' => 'identity', 'user_id' => $user->getKey()]);
        } finally {
            $context->clear();
        }

        return response()->json(['success' => true, 'data' => ['token' => $user->createToken($validated['device_name'] ?? 'api')->plainTextToken, 'token_type' => 'Bearer', 'user' => ['uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email], 'tenant' => ['uuid' => $tenant->uuid, 'name' => $tenant->name]]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['success' => true, 'data' => ['message' => 'Logged out.']]);
    }

    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);
        Password::sendResetLink(['email' => mb_strtolower(trim($validated['email']))]);

        return response()->json(['success' => true, 'data' => ['message' => 'If the account exists, password reset instructions will be sent.']]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255', 'token' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $status = Password::reset(
            ['email' => mb_strtolower(trim($validated['email'])), 'token' => $validated['token'], 'password' => $validated['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
            }
        );
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['success' => false, 'error' => ['code' => 'PASSWORD_RESET_FAILED', 'message' => 'Password reset could not be completed.'], 'request_id' => $request->attributes->get('request_id')], 422);
        }

        return response()->json(['success' => true, 'data' => ['message' => 'Password updated.']]);
    }

    public function beginMfa(Request $request, TotpMfaProvider $provider): JsonResponse
    {
        $credential = $request->user()->mfaCredentials()->firstOrNew(['type' => 'totp']);
        abort_if($credential->verified_at !== null, 409, 'MFA is already enabled.');
        $secret = $provider->generateSecret();
        $credential->forceFill(['secret_encrypted' => $secret, 'verified_at' => null, 'recovery_codes_hashed' => null])->save();
        $label = rawurlencode(config('app.name').':'.$request->user()->email);
        $uri = 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode(config('app.name')).'&digits=6&period=30';

        return response()->json(['success' => true, 'data' => ['secret' => $secret, 'otpauth_uri' => $uri]]);
    }

    public function confirmMfa(Request $request, TotpMfaProvider $provider): JsonResponse
    {
        $validated = $request->validate(['code' => 'required|string|size:6']);
        $credential = $request->user()->mfaCredentials()->where('type', 'totp')->firstOrFail();
        abort_if($credential->verified_at !== null, 409, 'MFA is already enabled.');
        abort_unless($provider->matches($credential->secret_encrypted ?? '', $validated['code']), 422, 'Invalid MFA code.');
        $codes = collect(range(1, 8))->map(fn () => strtoupper(bin2hex(random_bytes(5))))->all();
        $credential->forceFill(['verified_at' => now(), 'recovery_codes_hashed' => array_map(fn ($code) => RecoveryHash::make($code), $codes)])->save();

        return response()->json(['success' => true, 'data' => ['recovery_codes' => $codes]]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()]]);
        abort_unless(RecoveryHash::check($data['current_password'], $request->user()->password), 422, 'Current password is incorrect.');
        $request->user()->update(['password' => $data['password']]);
        $request->user()->tokens()->delete();

        return response()->json(['data' => ['message' => 'Password updated. Sign in again.']]);
    }
}
