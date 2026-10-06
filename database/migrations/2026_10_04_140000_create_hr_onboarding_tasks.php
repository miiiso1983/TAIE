<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'employee_id'], 'hr_onboarding_employee_fk')->references(['tenant_id', 'id'])->on('hr_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'assigned_to_user_id'], 'hr_onboarding_assignee_fk')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'completed_by_user_id'], 'hr_onboarding_completer_fk')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->index(['tenant_id', 'employee_id', 'status', 'due_on'], 'hr_onboarding_employee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_tasks');
    }
};
