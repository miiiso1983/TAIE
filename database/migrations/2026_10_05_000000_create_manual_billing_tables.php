<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subscription_id')->constrained('tenant_subscriptions')->restrictOnDelete();
            $t->string('number')->unique();
            $t->string('plan_name');
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->timestamp('due_at');
            $t->timestamp('paid_at')->nullable();
            $t->string('status')->default('open');
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('billing_payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $t->string('reference', 120);
            $t->unsignedBigInteger('amount_minor');
            $t->string('status')->default('pending');
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_invoices');
    }
};
