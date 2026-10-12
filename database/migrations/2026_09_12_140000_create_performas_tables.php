<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performas — credit issue ledgers (on-memo goods, prices later).
 *
 * Header stores the customer and lifecycle only. Money lives on the
 * settlement invoice, never on this document. Line outstanding is
 * issued − settled − returned.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('performas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('performa_number', 20)->unique()->comment('System-wide PRF-000125');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('performa_date');
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'performa_date']);
            $table->index('customer_id');
        });

        Schema::create('performa_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performa_id')->constrained('performas')->cascadeOnDelete();
            $table->string('line_type', 20);
            $table->foreignId('jewellery_item_id')->nullable()->constrained('jewellery_items')->restrictOnDelete();
            $table->foreignId('stone_id')->nullable()->constrained('stones')->restrictOnDelete();
            $table->string('item_code_snapshot', 20);
            $table->string('name_snapshot', 200);
            $table->string('category_snapshot', 100)->nullable();
            $table->string('metal_snapshot', 100)->nullable();
            $table->string('purity_snapshot', 100)->nullable();
            $table->decimal('gross_weight_snapshot', 10, 3)->nullable();
            $table->decimal('net_weight_snapshot', 10, 3)->nullable();
            $table->unsignedInteger('quantity_issued')->default(1);
            $table->unsignedInteger('quantity_settled')->default(0);
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['performa_id', 'sort_order']);
        });

        Schema::create('performa_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performa_id')->constrained('performas')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('payment_method', 30);
            $table->string('payment_reference', 100)->nullable();
            $table->timestamp('settled_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->timestamps();

            $table->unique('invoice_id');
            $table->index('performa_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performa_settlements');
        Schema::dropIfExists('performa_items');
        Schema::dropIfExists('performas');
    }
};
