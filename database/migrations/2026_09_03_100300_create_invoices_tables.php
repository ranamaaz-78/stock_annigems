<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices — immutable sale snapshots created from quotation conversion.
 *
 * verification_token is separate from uuid so printed QR never exposes the
 * internal admin URL. Full payment only — no partial payment columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('invoice_number', 20)->unique()->comment('System-wide INV-000125');
            $table->string('verification_token', 64)->unique()->comment('Public verify page only; not uuid');
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('customer_name_snapshot', 150);
            $table->string('customer_phone_snapshot', 30)->nullable();
            $table->date('invoice_date');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('tax_type', 20)->default('none');
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->string('payment_method', 30);
            $table->string('payment_reference', 100)->nullable();
            $table->string('status', 20)->default('completed');
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('voided_by_role', 30)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'invoice_date']);
            $table->index('customer_id');
            $table->index('verification_token');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->foreign('converted_invoice_id')
                ->references('id')
                ->on('invoices')
                ->nullOnDelete();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
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

            $table->index(['invoice_id', 'sort_order']);
        });

        Schema::create('invoice_item_stones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['converted_invoice_id']);
        });

        Schema::dropIfExists('invoice_item_stones');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
