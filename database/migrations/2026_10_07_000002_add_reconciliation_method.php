<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advisor_reconciliations', fn (Blueprint $table) => $table->string('method', 24)->default('control_totals'));
    }

    public function down(): void
    {
        Schema::table('advisor_reconciliations', fn (Blueprint $table) => $table->dropColumn('method'));
    }
};
