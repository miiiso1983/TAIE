<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('kind', 20);
            $t->string('source_system', 40);
            $t->char('content_hash', 64);
            $t->string('status', 20);
            $t->unsignedInteger('row_count');
            $t->unsignedInteger('error_count')->default(0);
            $t->longText('payload_encrypted')->nullable();
            $t->longText('errors_encrypted')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('committed_by')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'kind', 'source_system', 'content_hash'], 'advisor_batch_content_unique');
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
            $t->foreign(['tenant_id', 'committed_by'])->references(['tenant_id', 'user_id'])->on('tenant_users')->restrictOnDelete();
        });
        Schema::create('advisor_sales_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('employee_id');
            $t->string('source_system', 40);
            $t->string('external_id', 100);
            $t->date('invoice_date');
            $t->date('due_date');
            $t->char('currency', 3);
            $t->unsignedBigInteger('net_amount_minor');
            $t->timestamps();
            $t->unique(['tenant_id', 'source_system', 'external_id'], 'advisor_invoice_external_unique');
            $t->unique(['tenant_id', 'id']);
            foreach (['batch_id' => 'advisor_import_batches', 'customer_id' => 'sales_customers', 'employee_id' => 'hr_employees'] as $column => $table) {
                $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($table)->restrictOnDelete();
            }
            $t->index(['tenant_id', 'invoice_date']);
        });
        Schema::create('advisor_sales_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('invoice_id');
            $t->string('source_system', 40);
            $t->string('external_id', 100);
            $t->date('receipt_date');
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->timestamps();
            $t->unique(['tenant_id', 'source_system', 'external_id'], 'advisor_receipt_external_unique');
            $t->foreign(['tenant_id', 'batch_id'])->references(['tenant_id', 'id'])->on('advisor_import_batches')->restrictOnDelete();
            $t->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('advisor_sales_invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_sales_receipts');
        Schema::dropIfExists('advisor_sales_invoices');
        Schema::dropIfExists('advisor_import_batches');
    }
};
