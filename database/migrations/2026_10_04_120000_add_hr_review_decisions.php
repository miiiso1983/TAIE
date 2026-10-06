<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_performance_reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('decision_by_user_id')->nullable()->after('status');
            $table->text('decision_notes')->nullable()->after('decision_by_user_id');
            $table->timestamp('decided_at')->nullable()->after('decision_by_user_id');
            $table->index(['tenant_id', 'decision_by_user_id'], 'hr_reviews_decision_user_idx');
            $table->foreign(['tenant_id', 'decision_by_user_id'], 'hr_reviews_decision_user_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_performance_reviews', function (Blueprint $table) {
            $table->dropForeign('hr_reviews_decision_user_fk');
            $table->dropIndex('hr_reviews_decision_user_idx');
            $table->dropColumn(['decision_by_user_id', 'decision_notes', 'decided_at']);
        });
    }
};
