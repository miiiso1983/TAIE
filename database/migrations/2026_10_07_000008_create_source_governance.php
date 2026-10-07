<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_source_authorities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('kind', 24);
            $t->string('source_system', 40);
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('reviewed_by');
            $t->text('review_note_encrypted');
            $t->timestamps();
            $t->unique(['tenant_id', 'kind', 'version']);
            $t->foreign(['tenant_id', 'reviewed_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
        Schema::create('advisor_identity_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('kind', 24);
            $t->string('source_system', 40);
            $t->char('external_hash', 64);
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 12)->default('active');
            $t->text('revocation_note_encrypted')->nullable();
            $t->unsignedBigInteger('revoked_by')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->text('external_id_encrypted');
            $t->uuid('canonical_uuid');
            $t->unsignedBigInteger('reviewed_by');
            $t->text('review_note_encrypted');
            $t->timestamps();
            $t->unique(['tenant_id', 'kind', 'source_system', 'external_hash', 'version'], 'advisor_identity_external_unique');
            $t->foreign(['tenant_id', 'reviewed_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_identity_links');
        Schema::dropIfExists('advisor_source_authorities');
    }
};
