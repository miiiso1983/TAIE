<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Models\Tenant;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class TenantController
{
    public function index(Request $request)
    {
        return response()->json(Tenant::query()->withCount('users')->orderByDesc('id')->paginate(25)->through(fn ($tenant) => $this->present($tenant)));
    }

    public function store(Request $request, TenantProvisioner $provisioner)
    {
        $request->merge(['slug' => mb_strtolower(trim((string) $request->input('slug'))), 'owner_email' => mb_strtolower(trim((string) $request->input('owner_email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => 'required|string|max:80|regex:/^[a-z0-9][a-z0-9-]*$/',
            'owner_name' => 'required|string|max:255', 'owner_email' => 'required|email|max:255',
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => 'required|uuid'])->validate()['key'];
        $hash = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($request, $data, $key, $hash, $provisioner) {
            abort_unless(DB::table('platform_owners')->where('user_id', $request->user()->id)->lockForUpdate()->first(), 403);
            $previous = DB::table('platform_tenant_creations')->where('actor_id', $request->user()->id)->where('request_key', $key)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->payload_hash, $hash), 409, 'Request key already used with different data.');

                return response()->json(['data' => $this->present(Tenant::query()->withCount('users')->findOrFail($previous->tenant_id))]);
            }
            Validator::make($data, ['slug' => 'unique:tenants,slug', 'owner_email' => 'unique:users,email'])->validate();
            $tenant = $provisioner->create($data, $request->user()->id);
            DB::table('platform_tenant_creations')->insert(['actor_id' => $request->user()->id, 'request_key' => $key, 'payload_hash' => $hash, 'tenant_id' => $tenant->id, 'created_at' => now()]);

            return response()->json(['data' => $this->present($tenant->loadCount('users'))], 201);
        });
    }

    private function present(Tenant $tenant): array
    {
        return ['uuid' => $tenant->uuid, 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status, 'users_count' => $tenant->users_count, 'created_at' => $tenant->created_at];
    }
}
