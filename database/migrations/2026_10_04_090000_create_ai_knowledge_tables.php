<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('status')->default('active');
            $table->string('sensitivity')->default('internal');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_id');
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->longText('body_encrypted');
            $table->char('content_hash', 64);
            $table->string('status')->default('queued');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('processing_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'source_id', 'content_hash']);
            $table->foreign(['tenant_id', 'source_id'])->references(['tenant_id', 'id'])->on('knowledge_sources')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $table->index(['tenant_id', 'source_id', 'status']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('chunk_index');
            $table->longText('content_encrypted');
            $table->json('embedding');
            $table->string('embedding_model');
            $table->timestamps();
            $table->unique(['tenant_id', 'document_id', 'chunk_index']);
            $table->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('knowledge_documents')->cascadeOnDelete();
            $table->index(['tenant_id', 'embedding_model']);
        });

        Schema::create('ai_agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('system_instructions');
            $table->json('allowed_source_uuids')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agent_profile_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('open');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'agent_profile_id'])->references(['tenant_id', 'id'])->on('ai_agent_profiles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('tenant_users')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->string('role');
            $table->longText('content_encrypted');
            $table->json('citations')->nullable();
            $table->json('usage')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('ai_conversations')->cascadeOnDelete();
            $table->index(['tenant_id', 'conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_agent_profiles');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('knowledge_sources');
    }
};
