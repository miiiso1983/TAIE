<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advisor_decisions', function (Blueprint $t) {
            $t->unsignedBigInteger('report_id')->nullable()->change();
            $t->foreignId('forecast_run_id')->nullable()->constrained('advisor_product_forecast_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('advisor_decisions')->whereNull('report_id')->exists()) {
            throw new RuntimeException('Forecast decisions exist; rollback would invalidate retained evidence.');
        }
        Schema::table('advisor_decisions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('forecast_run_id');
            $t->unsignedBigInteger('report_id')->nullable(false)->change();
        });
    }
};
