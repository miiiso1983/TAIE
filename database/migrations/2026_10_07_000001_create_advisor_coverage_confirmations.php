<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_coverage_confirmations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->date('period_from');
            $t->date('period_to');
            $t->date('as_of');
            $t->string('currency', 3);
            $t->char('fingerprint', 64);
            $t->longText('review_encrypted');
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->index(['tenant_id', 'period_from', 'period_to', 'as_of', 'currency'], 'advisor_coverage_scope');
            $t->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_coverage_confirmations');
    }
};
