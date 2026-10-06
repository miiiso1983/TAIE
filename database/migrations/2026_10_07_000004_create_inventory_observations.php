<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_inventory_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('area_id')->nullable();
            $t->string('kind', 24);
            $t->string('source_system', 40);
            $t->string('external_id', 100);
            $t->date('observed_on');
            $t->date('end_date')->nullable();
            $t->decimal('quantity', 12, 3)->nullable();
            $t->char('currency', 3)->nullable();
            $t->unsignedBigInteger('amount_minor')->nullable();
            $t->foreignId('invoice_line_id')->nullable()->constrained('advisor_invoice_lines')->restrictOnDelete();
            $t->foreignId('return_id')->nullable()->constrained('advisor_sales_returns')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'source_system', 'kind', 'external_id'], 'advisor_inventory_external_unique');
            $t->index(['tenant_id', 'kind', 'product_id', 'observed_on'], 'advisor_inventory_period_index');
            foreach (['batch_id' => 'advisor_import_batches', 'product_id' => 'advisor_products', 'area_id' => 'advisor_areas'] as $col => $table) {
                $t->foreign(['tenant_id', $col])->references(['tenant_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_inventory_observations');
    }
};
