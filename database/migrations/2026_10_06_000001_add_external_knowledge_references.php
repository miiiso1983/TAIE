<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('business_activity', 30)->default('general');
        });
        Schema::table('knowledge_sources', function (Blueprint $t) {
            $t->string('origin', 30)->default('company');
            $t->string('reference_key', 60)->nullable();
            $t->json('external_metadata')->nullable();
            $t->text('external_review_note_encrypted')->nullable();
            $t->unique(['tenant_id', 'reference_key'], 'knowledge_reference_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $t) {
            $t->dropUnique('knowledge_reference_tenant_unique');
            $t->dropColumn(['origin', 'reference_key', 'external_metadata', 'external_review_note_encrypted']);
        });
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('business_activity'));
    }
};
