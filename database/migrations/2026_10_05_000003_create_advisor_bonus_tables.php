<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_sales_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('invoice_id');
            $t->string('source_system', 40);
            $t->string('external_id', 100);
            $t->date('return_date');
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->timestamps();
            $t->unique(['tenant_id', 'source_system', 'external_id'], 'advisor_return_external_unique');
            $t->foreign(['tenant_id', 'batch_id'])->references(['tenant_id', 'id'])->on('advisor_import_batches')->restrictOnDelete();
            $t->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('advisor_sales_invoices')->restrictOnDelete();
        });
        Schema::create('advisor_sales_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('employee_id');
            $t->string('source_system', 40);
            $t->string('external_id', 100);
            $t->date('period_from');
            $t->date('period_to');
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->timestamps();
            $t->unique(['tenant_id', 'source_system', 'external_id'], 'advisor_target_external_unique');
            $t->foreign(['tenant_id', 'batch_id'])->references(['tenant_id', 'id'])->on('advisor_import_batches')->restrictOnDelete();
            $t->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $t->index(['tenant_id', 'employee_id', 'currency', 'period_from'], 'advisor_targets_employee_currency_period_idx');
        });
        Schema::create('advisor_bonus_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('name', 120);
            $t->unsignedInteger('version');
            $t->date('valid_from');
            $t->date('valid_to');
            $t->char('currency', 3);
            $t->string('mode', 20);
            $t->json('rules');
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->unique(['tenant_id', 'name', 'version'], 'advisor_policy_version_unique');
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
        Schema::create('advisor_bonus_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('employee_id');
            $t->unsignedBigInteger('policy_id');
            $t->date('period_from');
            $t->date('period_to');
            $t->date('as_of');
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->text('reason_encrypted');
            $t->longText('snapshot_encrypted');
            $t->char('fingerprint', 64);
            $t->string('status', 20)->default('pending');
            $t->unsignedBigInteger('submitted_by');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('decision_reason_encrypted')->nullable();
            $t->boolean('overridden')->default(false);
            $t->timestamp('evaluated_at');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'id']);
            foreach (['employee_id' => 'hr_employees', 'policy_id' => 'advisor_bonus_policies'] as $column => $table) {
                $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($table)->restrictOnDelete();
            }
            foreach (['submitted_by', 'decided_by'] as $column) {
                $t->foreign(['tenant_id', $column])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        foreach (['advisor_bonus_requests', 'advisor_bonus_policies', 'advisor_sales_targets', 'advisor_sales_returns'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
