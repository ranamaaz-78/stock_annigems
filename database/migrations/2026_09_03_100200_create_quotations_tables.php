<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations and line items — branch-scoped priced documents.
 *
 * Prices live here only (never on jewellery_items or stones). Panel defaults
 * store the last bulk-applied rate values; each line keeps its own rates.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('quotation_number', 20)->unique()->comment('System-wide QTN-000125');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('walkin_name', 150)->nullable();
            $table->string('walkin_phone', 30)->nullable();
            $table->date('quotation_date');
            $table->decimal('default_gold_rate', 12, 2)->nullable();
            $table->decimal('default_stone_price', 12, 2)->nullable();
            $table->string('default_making_charge_type', 20)->nullable();
            $table->decimal('default_making_charge_value', 12, 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('tax_type', 20)->default('none');
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('converted_invoice_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'quotation_date']);
            $table->index('customer_id');
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->string('line_type', 20);
            $table->foreignId('jewellery_item_id')->nullable()->constrained('jewellery_items')->restrictOnDelete();
            $table->foreignId('stone_id')->nullable()->constrained('stones')->restrictOnDelete();
            $table->string('item_code_snapshot', 20);
            $table->string('name_snapshot', 200);
            $table->string('category_snapshot', 100)->nullable();
            $table->string('metal_snapshot', 100)->nullable();
            $table->string('purity_snapshot', 100)->nullable();
            $table->foreignId('purity_id_snapshot')->nullable()->constrained('purities')->nullOnDelete();
            $table->decimal('gross_weight_snapshot', 10, 3)->nullable();
            $table->decimal('net_weight_snapshot', 10, 3)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('gold_rate_applied', 12, 2)->default(0);
            $table->decimal('gold_value', 14, 2)->default(0);
            $table->string('making_charge_type', 20)->default('none');
            $table->decimal('making_charge_value', 12, 2)->default(0);
            $table->decimal('making_charges', 14, 2)->default(0);
            $table->decimal('stone_value', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quotation_id', 'sort_order']);
        });

        Schema::create('quotation_item_stones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_item_id')->constrained('quotation_items')->cascadeOnDelete();
            $table->foreignId('jewellery_item_stone_id')->nullable()->constrained('jewellery_item_stones')->nullOnDelete();
            $table->string('stone_name_snapshot', 150);
            $table->string('stone_type_snapshot', 100);
            $table->decimal('carat_snapshot', 8, 3)->default(0);
            $table->string('shape_snapshot', 100)->nullable();
            $table->string('color_snapshot', 100)->nullable();
            $table->string('clarity_snapshot', 100)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price_per_piece', 12, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('quotation_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_drafts');
        Schema::dropIfExists('quotation_item_stones');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
