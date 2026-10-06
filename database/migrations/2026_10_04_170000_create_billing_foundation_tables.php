<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_amount');
            $table->string('interval')->default('month');
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('status')->default('trialing')->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->string('external_provider')->nullable();
            $table->string('external_subscription_id')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->unique(['external_provider', 'external_subscription_id'], 'billing_external_subscription_unique');
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 100);
            $table->unsignedBigInteger('quantity');
            $table->string('idempotency_key', 128);
            $table->timestamp('occurred_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'metric', 'idempotency_key'], 'usage_tenant_metric_idempotency_unique');
            $table->index(['tenant_id', 'metric', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('billing_plans');
    }
};
