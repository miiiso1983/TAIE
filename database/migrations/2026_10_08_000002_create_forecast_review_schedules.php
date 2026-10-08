<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_forecast_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('owner_id');
            $t->uuid('product_uuid');
            $t->uuid('area_uuid');
            $t->string('source_system', 40);
            $t->unsignedInteger('history_weeks');
            $t->unsignedInteger('local_hour');
            $t->boolean('enabled')->default(true);
            $t->boolean('review_pending')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->date('review_from');
            $t->date('review_to');
            $t->timestamp('next_run_at')->index();
            $t->foreignId('last_forecast_run_id')->nullable()->constrained('advisor_product_forecast_runs')->restrictOnDelete();
            $t->string('last_error_code')->nullable();
            $t->timestamps();
            $t->foreign(['tenant_id', 'owner_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_forecast_schedules');
    }
};
