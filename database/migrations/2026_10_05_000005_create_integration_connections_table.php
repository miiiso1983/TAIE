<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('name', 120);
            $t->string('provider', 30);
            $t->string('source_system', 40);
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
            $t->unique(['tenant_id', 'source_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};
