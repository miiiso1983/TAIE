<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_owners', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $t->timestamp('granted_at');
        });
        Schema::create('platform_tenant_creations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'request_key'], 'platform_creation_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_tenant_creations');
        Schema::dropIfExists('platform_owners');
    }
};
