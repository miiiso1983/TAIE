<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_company_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('kind', 16);
            $t->string('name', 120);
            $t->unsignedInteger('version');
            $t->string('status', 12)->default('draft');
            $t->date('valid_from');
            $t->date('valid_to');
            $t->text('rules_encrypted');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->text('approval_note_encrypted')->nullable();
            $t->longText('status_history_encrypted')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'kind', 'name', 'version'], 'advisor_rule_version_unique');
        });
        Schema::create('advisor_rule_simulations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->foreignId('policy_id')->constrained('advisor_company_rules')->restrictOnDelete();
            $t->unsignedBigInteger('created_by');
            $t->char('fingerprint', 64);
            $t->boolean('passed');
            $t->longText('snapshot_encrypted');
            $t->timestamps();
            $t->unique(['tenant_id', 'policy_id', 'fingerprint'], 'advisor_simulation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_rule_simulations');
        Schema::dropIfExists('advisor_company_rules');
    }
};
