<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_bonus_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('request_id')->constrained('advisor_bonus_requests')->restrictOnDelete();
            $t->unsignedBigInteger('actor_id');
            $t->unsignedTinyInteger('stage');
            $t->char('fingerprint', 64);
            $t->string('decision', 12);
            $t->text('reason_encrypted');
            $t->boolean('overridden')->default(false);
            $t->timestamp('created_at');
            $t->unique(['request_id', 'fingerprint', 'stage'], 'advisor_bonus_stage_unique');
            $t->unique(['request_id', 'fingerprint', 'actor_id'], 'advisor_bonus_actor_unique');
            $t->foreign(['tenant_id', 'actor_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_bonus_approvals');
    }
};
