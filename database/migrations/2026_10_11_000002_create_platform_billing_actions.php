<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_billing_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('payload_hash', 64);
            $table->json('result');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'request_key'], 'platform_billing_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_billing_actions');
    }
};
