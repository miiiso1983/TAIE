<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->char('currency_code', 3);
            $table->string('status')->default('draft');
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedInteger('excluded_employee_count')->default(0);
            $table->text('base_total_encrypted');
            $table->text('allowance_total_encrypted');
            $table->text('deduction_total_encrypted');
            $table->text('net_total_encrypted');
            $table->unsignedBigInteger('prepared_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'currency_code', 'period_starts_on', 'period_ends_on'], 'hr_payroll_period_currency_unique');
            $table->foreign(['tenant_id', 'prepared_by_user_id'], 'hr_payroll_preparer_fk')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'approved_by_user_id'], 'hr_payroll_approver_fk')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'paid_by_user_id'], 'hr_payroll_payer_fk')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'period_ends_on'], 'hr_payroll_status_period_idx');
        });

        Schema::create('hr_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('payroll_run_id');
            $table->unsignedBigInteger('employee_id');
            $table->text('base_pay_encrypted');
            $table->text('allowances_encrypted');
            $table->text('deductions_encrypted');
            $table->text('net_pay_encrypted');
            $table->timestamps();
            $table->unique(['tenant_id', 'payroll_run_id', 'employee_id'], 'hr_payroll_employee_once_unique');
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'payroll_run_id'], 'hr_payroll_item_run_fk')->references(['tenant_id', 'id'])->on('hr_payroll_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'employee_id'], 'hr_payroll_item_employee_fk')->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->index(['tenant_id', 'employee_id'], 'hr_payroll_item_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_items');
        Schema::dropIfExists('hr_payroll_runs');
    }
};
