<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_plans', fn (Blueprint $table) => $table->boolean('is_public')->default(false));
        Schema::create('company_trial_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->string('phone_fingerprint', 64)->unique();
            $table->foreignId('tenant_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('profile_encrypted')->nullable();
            $table->json('result')->nullable();
            $table->string('policy_version', 30);
            $table->timestamp('consented_at');
            $table->timestamps();
        });
        DB::table('billing_plans')->insertOrIgnore([
            'uuid' => (string) Str::uuid(), 'code' => 'self_service_trial_v1', 'name' => 'تجربة مجانية — 4 أيام',
            'currency' => 'USD', 'unit_amount' => 0, 'interval' => 'trial',
            'limits' => json_encode(['users' => 5, 'ai_requests_per_month' => 25, 'ai_requests_per_trial' => 25]),
            'is_active' => true, 'is_public' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_trial_registrations');
        // Preserve subscription history; the internal trial plan is not deleted.
        Schema::table('billing_plans', fn (Blueprint $table) => $table->dropColumn('is_public'));
    }
};
