<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('requisition_id');
            $table->unique(['tenant_id', 'employee_id'], 'hr_applications_employee_unique');
            $table->foreign(['tenant_id', 'employee_id'], 'hr_applications_employee_fk')
                ->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_applications', function (Blueprint $table) {
            $table->dropForeign('hr_applications_employee_fk');
            $table->dropUnique('hr_applications_employee_unique');
            $table->dropColumn('employee_id');
        });
    }
};
