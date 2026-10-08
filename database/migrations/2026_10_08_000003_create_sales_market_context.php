<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_market_context', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('kind', 20);
            $t->string('rate_basis', 20)->nullable();
            $t->date('period_from');
            $t->date('period_to');
            $t->uuid('area_uuid')->nullable();
            $t->string('status', 20)->default('active');
            $t->unsignedBigInteger('reviewed_by');
            $t->unsignedBigInteger('revoked_by')->nullable();
            $t->longText('payload_encrypted');
            $t->longText('revocation_note_encrypted')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'kind', 'status', 'period_from'], 'advisor_market_scope_index');
            $t->foreign(['tenant_id', 'reviewed_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_market_context');
    }
};
