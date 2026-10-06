<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('returned_by_user_id')->nullable()->after('paid_by_user_id');
            $table->text('return_reason')->nullable()->after('returned_by_user_id');
            $table->timestamp('returned_at')->nullable()->after('return_reason');
            $table->foreign(['tenant_id', 'returned_by_user_id'], 'hr_payroll_returner_fk')->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropForeign('hr_payroll_returner_fk');
            $table->dropColumn(['returned_by_user_id', 'return_reason', 'returned_at']);
        });
    }
};
