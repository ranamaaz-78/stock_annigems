<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery notes (albarán): goods handed to a client with a price and an
 * optional discount, but no tax and no payment.
 *
 * Stock leaves the shelf when the note is issued. The note stays open until it
 * is cancelled (stock returns) or converted to an invoice (which adds tax and
 * takes payment, without cutting stock a second time). Line snapshots mirror
 * invoice_items so a note can be copied onto an invoice unchanged.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('delivery_note_number', 20)->unique()->comment('System-wide ALB-000125');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('customer_name_snapshot', 150);
            $table->string('customer_phone_snapshot', 30)->nullable();
            $table->string('customer_address_snapshot', 500)->nullable();
            $table->date('delivery_date');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->string('status', 20)->default('issued');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancelled_by_role', 30)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'delivery_date']);
            $table->index('customer_id');
        });

        Schema::create('delivery_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained('delivery_notes')->cascadeOnDelete();
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

            $table->index(['delivery_note_id', 'sort_order']);
        });

        Schema::create('delivery_note_item_stones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_item_id')->constrained('delivery_note_items')->cascadeOnDelete();
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

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('delivery_note_id')->nullable()->after('performa_id')
                ->constrained('delivery_notes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_note_id');
        });

        Schema::dropIfExists('delivery_note_item_stones');
        Schema::dropIfExists('delivery_note_items');
        Schema::dropIfExists('delivery_notes');
    }
};
