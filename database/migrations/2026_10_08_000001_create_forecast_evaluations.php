<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_forecast_evaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->foreignId('forecast_run_id')->constrained('advisor_product_forecast_runs')->restrictOnDelete();
            $t->unsignedBigInteger('reviewed_by');
            $t->char('input_hash', 64);
            $t->longText('snapshot_encrypted');
            $t->timestamps();
            $t->unique(['tenant_id', 'input_hash'], 'advisor_forecast_evaluation_unique');
            $t->foreign(['tenant_id', 'reviewed_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_forecast_evaluations');
    }
};
