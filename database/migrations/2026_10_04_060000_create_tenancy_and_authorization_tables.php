<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug')->unique();
            $table->string('status')->default('active')->index();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('default_language', 12)->default('en');
            $table->char('currency_code', 3)->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('guard_name')->default('api');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name', 'guard_name']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name')->default('api');
            $table->string('module')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['tenant_id', 'role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('mfa_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('secret_encrypted')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('recovery_codes_hashed')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_credentials');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenants');
    }
};
