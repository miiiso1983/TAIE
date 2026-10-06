<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Keep immutable historical identifiers if the related tenant or user is purged.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('module')->nullable();
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->unsignedBigInteger('sequence')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->longText('canonical_payload');
            $table->char('current_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('severity')->default('medium');
            $table->text('description')->nullable();
            $table->json('payload_json')->nullable();
            $table->string('action_taken')->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('event_uuid')->unique();
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('key');
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->string('status')->default('processing');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'endpoint', 'key'], 'idempotency_scope_key_unique');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->string('status')->default('received')->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('tenant_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('disk')->default('private');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sensitivity')->default('internal');
            $table->timestamps();
            $table->unique(['tenant_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_files');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_logs');
    }
};
