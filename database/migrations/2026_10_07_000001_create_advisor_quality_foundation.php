<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['advisor_products', 'advisor_areas'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('batch_id');
                $t->string('code', 60);
                if ($table === 'advisor_products') {
                    $t->string('name', 255);
                    $t->string('base_unit', 60);
                } else {
                    $t->char('country_code', 2)->default('IQ');
                    $t->string('province_name', 120);
                    $t->string('city_name', 120);
                }
                $t->timestamps();
                $t->unique(['tenant_id', 'code']);
                $t->unique(['tenant_id', 'id']);
                $t->foreign(['tenant_id', 'batch_id'])->references(['tenant_id', 'id'])->on('advisor_import_batches')->restrictOnDelete();
            });
        }
        Schema::create('advisor_customer_areas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('area_id');
            $t->timestamps();
            $t->unique(['tenant_id', 'customer_id']);
            foreach (['batch_id' => 'advisor_import_batches', 'customer_id' => 'sales_customers', 'area_id' => 'advisor_areas'] as $column => $table) {
                $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::table('advisor_sales_invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('area_id')->nullable();
            $t->foreign(['tenant_id', 'area_id'])->references(['tenant_id', 'id'])->on('advisor_areas')->restrictOnDelete();
        });
        Schema::create('advisor_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('product_id');
            $t->string('source_system', 40);
            $t->string('external_id', 100);
            $t->decimal('quantity', 12, 3);
            $t->unsignedBigInteger('net_amount_minor');
            $t->string('base_unit', 60);
            $t->timestamps();
            $t->unique(['tenant_id', 'source_system', 'external_id'], 'advisor_line_external_unique');
            foreach (['batch_id' => 'advisor_import_batches', 'invoice_id' => 'advisor_sales_invoices', 'product_id' => 'advisor_products'] as $column => $table) {
                $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('advisor_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('source_system', 40);
            $t->date('period_from');
            $t->date('period_to');
            $t->date('as_of');
            $t->char('currency', 3);
            $t->string('status', 20);
            $t->char('fingerprint', 64);
            $t->longText('snapshot_encrypted');
            $t->text('evidence_reference_encrypted');
            $t->text('review_note_encrypted');
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $t->index(['tenant_id', 'source_system', 'period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_reconciliations');
        Schema::dropIfExists('advisor_invoice_lines');
        Schema::table('advisor_sales_invoices', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'area_id']);
            $t->dropColumn('area_id');
        });
        Schema::dropIfExists('advisor_customer_areas');
        Schema::dropIfExists('advisor_areas');
        Schema::dropIfExists('advisor_products');
    }
};
