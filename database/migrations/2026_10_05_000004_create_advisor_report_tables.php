<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('kind', 10);
            $t->date('period_from');
            $t->date('period_to');
            $t->date('as_of');
            $t->unsignedInteger('version');
            $t->char('fingerprint', 64);
            $t->longText('snapshot_encrypted');
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->unique(['tenant_id', 'kind', 'period_from', 'period_to', 'as_of', 'version'], 'advisor_report_version_unique');
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
        Schema::create('advisor_report_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('kind', 10);
            $t->string('frequency', 10);
            $t->unsignedTinyInteger('local_hour');
            $t->unsignedBigInteger('owner_id');
            $t->boolean('enabled')->default(true);
            $t->timestamp('next_run_at');
            $t->timestamp('last_run_at')->nullable();
            $t->string('last_error_code', 40)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'kind', 'frequency'], 'advisor_schedule_frequency_unique');
            $t->foreign(['tenant_id', 'owner_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $t->index(['enabled', 'next_run_at']);
        });
        Schema::create('advisor_report_explanations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('report_id');
            $t->unsignedBigInteger('user_id');
            $t->text('question_encrypted');
            $t->longText('answer_encrypted');
            $t->json('references');
            $t->json('usage');
            $t->string('model', 120);
            $t->timestamps();
            $t->foreign(['tenant_id', 'report_id'])->references(['tenant_id', 'id'])->on('advisor_reports')->restrictOnDelete();
            $t->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['advisor_report_explanations', 'advisor_report_schedules', 'advisor_reports'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
