<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('code', 40);
            $table->string('name');
            $table->unsignedBigInteger('parent_department_id')->nullable();
            $table->unsignedBigInteger('manager_employee_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'parent_department_id'])->references(['tenant_id', 'id'])->on('hr_departments')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('code', 40);
            $table->string('title');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('grade')->nullable();
            $table->string('employment_type')->default('full_time');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'department_id'])->references(['tenant_id', 'id'])->on('hr_departments')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('employee_number', 60);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('work_email')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('manager_employee_id')->nullable();
            $table->string('employment_status')->default('active');
            $table->string('employment_type')->default('full_time');
            $table->date('hired_at')->nullable();
            $table->date('terminated_at')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('national_id_encrypted')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->json('emergency_contact_encrypted')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'employee_number']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'work_email']);
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'department_id'])->references(['tenant_id', 'id'])->on('hr_departments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'position_id'])->references(['tenant_id', 'id'])->on('hr_positions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'manager_employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->index(['tenant_id', 'employment_status']);
            $table->index(['tenant_id', 'department_id', 'employment_status']);
        });

        Schema::table('hr_departments', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'manager_employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
        });

        Schema::create('hr_compensation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->text('annual_base_encrypted');
            $table->char('currency_code', 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'approved_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->index(['tenant_id', 'employee_id', 'effective_from']);
        });

        Schema::create('hr_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->string('shift_code', 40)->default('default');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('status')->default('present');
            $table->unsignedInteger('minutes_late')->default(0);
            $table->unsignedInteger('minutes_worked')->default(0);
            $table->string('source')->default('manual');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_id', 'work_date', 'shift_code']);
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'recorded_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->index(['tenant_id', 'work_date', 'status']);
        });

        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('code', 40);
            $table->string('name');
            $table->boolean('paid')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->decimal('annual_allowance_days', 7, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('hr_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedSmallInteger('year');
            $table->decimal('allocated_days', 7, 2)->default(0);
            $table->decimal('used_days', 7, 2)->default(0);
            $table->decimal('pending_days', 7, 2)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_id', 'leave_type_id', 'year']);
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'leave_type_id'])->references(['tenant_id', 'id'])->on('hr_leave_types')->cascadeOnDelete();
        });

        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('requested_days', 7, 2);
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'leave_type_id'])->references(['tenant_id', 'id'])->on('hr_leave_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'decided_by'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'starts_on']);
        });

        Schema::create('hr_performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('hr_performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('reviewer_employee_id');
            $table->decimal('score', 5, 2)->nullable();
            $table->text('strengths')->nullable();
            $table->text('development_areas')->nullable();
            $table->text('comments')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'cycle_id', 'employee_id', 'reviewer_employee_id']);
            $table->foreign(['tenant_id', 'cycle_id'])->references(['tenant_id', 'id'])->on('hr_performance_cycles')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'reviewer_employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
        });

        Schema::create('hr_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(100);
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->date('due_on')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'cycle_id'])->references(['tenant_id', 'id'])->on('hr_performance_cycles')->restrictOnDelete();
            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('hr_job_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('headcount')->default(1);
            $table->string('employment_type')->default('full_time');
            $table->string('status')->default('draft');
            $table->date('opened_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'position_id'])->references(['tenant_id', 'id'])->on('hr_positions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'department_id'])->references(['tenant_id', 'id'])->on('hr_departments')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->text('resume_path')->nullable();
            $table->json('skills_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('hr_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('requisition_id');
            $table->string('status')->default('applied');
            $table->decimal('screening_score', 5, 2)->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamps();
            $table->unique(['tenant_id', 'candidate_id', 'requisition_id']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'candidate_id'])->references(['tenant_id', 'id'])->on('hr_candidates')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'requisition_id'])->references(['tenant_id', 'id'])->on('hr_job_requisitions')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('interviewer_employee_id')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('status')->default('scheduled');
            $table->decimal('score', 5, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'application_id'])->references(['tenant_id', 'id'])->on('hr_applications')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'interviewer_employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->index(['tenant_id', 'scheduled_at', 'status']);
        });

        Schema::create('hr_employee_relations_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('opened_by_employee_id')->nullable();
            $table->unsignedBigInteger('assigned_to_employee_id')->nullable();
            $table->string('case_type');
            $table->string('severity')->default('medium');
            $table->string('status')->default('open');
            $table->string('subject');
            $table->longText('description_encrypted');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'opened_by_employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'assigned_to_employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'severity']);
        });

        Schema::create('sales_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('customer_number', 60);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('type')->default('organization');
            $table->string('industry')->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'customer_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'owner_user_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });

        Schema::create('sales_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('job_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('sales_customers')->restrictOnDelete();
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'email']);
        });

        Schema::create('sales_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->default('new');
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('converted_customer_id')->nullable();
            $table->unsignedBigInteger('converted_contact_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'owner_user_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'converted_customer_id'])->references(['tenant_id', 'id'])->on('sales_customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'converted_contact_id'])->references(['tenant_id', 'id'])->on('sales_contacts')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'owner_user_id']);
        });

        Schema::create('sales_pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('sales_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('pipeline_id');
            $table->string('name');
            $table->unsignedSmallInteger('sort_order');
            $table->decimal('default_probability', 5, 2)->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'pipeline_id', 'name']);
            $table->unique(['tenant_id', 'pipeline_id', 'id']);
            $table->foreign(['tenant_id', 'pipeline_id'])->references(['tenant_id', 'id'])->on('sales_pipelines')->cascadeOnDelete();
            $table->index(['tenant_id', 'pipeline_id', 'sort_order']);
        });

        Schema::create('sales_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('pipeline_id');
            $table->unsignedBigInteger('stage_id');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->char('currency_code', 3);
            $table->decimal('probability', 5, 2)->default(0);
            $table->date('expected_close_on')->nullable();
            $table->string('status')->default('open');
            $table->text('description')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('loss_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('sales_customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('sales_contacts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'lead_id'])->references(['tenant_id', 'id'])->on('sales_leads')->restrictOnDelete();
            $table->foreign(['tenant_id', 'owner_user_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pipeline_id', 'stage_id'])->references(['tenant_id', 'pipeline_id', 'id'])->on('sales_pipeline_stages')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'expected_close_on']);
            $table->index(['tenant_id', 'owner_user_id', 'status']);
        });

        Schema::create('sales_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('type');
            $table->string('subject');
            $table->longText('description')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status')->default('open');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->nullableMorphs('subjectable');
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->foreign(['tenant_id', 'assigned_to'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('name');
            $table->string('metric');
            $table->decimal('target_value', 18, 2);
            $table->decimal('actual_value', 18, 2)->default(0);
            $table->char('currency_code', 3)->nullable();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->foreign(['tenant_id', 'owner_user_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->index(['tenant_id', 'metric', 'period_starts_on', 'period_ends_on']);
        });

        Schema::create('sales_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->char('currency_code', 3);
            $table->decimal('pipeline_amount', 18, 2)->default(0);
            $table->decimal('weighted_amount', 18, 2)->default(0);
            $table->decimal('commit_amount', 18, 2)->default(0);
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->unique(['tenant_id', 'period_starts_on', 'period_ends_on', 'name']);
            $table->index(['tenant_id', 'status', 'period_ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_forecasts');
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('sales_activities');
        Schema::dropIfExists('sales_opportunities');
        Schema::dropIfExists('sales_pipeline_stages');
        Schema::dropIfExists('sales_pipelines');
        Schema::dropIfExists('sales_leads');
        Schema::dropIfExists('sales_contacts');
        Schema::dropIfExists('sales_customers');
        Schema::dropIfExists('hr_employee_relations_cases');
        Schema::dropIfExists('hr_interviews');
        Schema::dropIfExists('hr_applications');
        Schema::dropIfExists('hr_candidates');
        Schema::dropIfExists('hr_job_requisitions');
        Schema::dropIfExists('hr_goals');
        Schema::dropIfExists('hr_performance_reviews');
        Schema::dropIfExists('hr_performance_cycles');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_leave_balances');
        Schema::dropIfExists('hr_leave_types');
        Schema::dropIfExists('hr_attendance_records');
        Schema::dropIfExists('hr_compensation_records');
        Schema::table('hr_departments', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'manager_employee_id']);
        });
        Schema::dropIfExists('hr_employees');
        Schema::dropIfExists('hr_positions');
        Schema::dropIfExists('hr_departments');
    }
};
