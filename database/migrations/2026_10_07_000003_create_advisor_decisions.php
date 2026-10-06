<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->foreignId('report_id')->constrained('advisor_reports')->restrictOnDelete();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 24)->default('proposed');
            $t->date('due_date');
            $t->unsignedInteger('version')->default(1);
            $t->text('details_encrypted');
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'due_date']);
        });
        Schema::create('advisor_decision_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('decision_id')->constrained('advisor_decisions')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 24);
            $t->text('evidence_encrypted');
            $t->timestamp('created_at');
            $t->unique(['decision_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_decision_events');
        Schema::dropIfExists('advisor_decisions');
    }
};
