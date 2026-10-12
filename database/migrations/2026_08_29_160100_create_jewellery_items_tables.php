<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jewellery pieces: one row per physical item (never a quantity column).
 *
 * barcode encodes item_code for internal scanning. qr_token is a separate
 * cryptographically random public identifier — never the uuid — so a tag
 * cannot be used as an admin URL.
 *
 * Derived weights (stone carat/grams, net) are stored after server
 * recalculation; clients cannot set them.
 *
 * No purchase_cost, sale_price, making_charges, rate, or valuation columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jewellery_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('item_code', 20)->unique()->comment('System-wide JWL-000125; never from input');
            $table->string('barcode', 64)->unique()->comment('Code 128 payload; encodes item_code');
            $table->string('qr_token', 64)->unique()->comment('Public product page token; not uuid');
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('sub_category_id')->nullable()->constrained('sub_categories')->restrictOnDelete();
            $table->string('name', 200);
            $table->foreignId('metal_type_id')->constrained('metal_types')->restrictOnDelete();
            $table->foreignId('purity_id')->constrained('purities')->restrictOnDelete();
            $table->decimal('gross_weight', 10, 3)->comment('Grams; required');
            $table->decimal('stone_weight_carat', 10, 3)->default(0)->comment('Derived: sum of allocated carats');
            $table->decimal('stone_weight_grams', 10, 3)->default(0)->comment('Derived: carat × 0.2');
            $table->decimal('net_weight', 10, 3)->default(0)->comment('Derived: gross − stone grams');
            $table->text('description')->nullable();
            $table->string('status', 30);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'category_id']);
            $table->index('item_code');
        });

        Schema::create('jewellery_item_stones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jewellery_item_id')->constrained('jewellery_items')->cascadeOnDelete();
            $table->foreignId('stone_id')->constrained('stones')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('stone_code_snapshot', 20);
            $table->string('stone_name_snapshot', 150);
            $table->decimal('carat_snapshot', 8, 3);
            $table->string('stone_type_snapshot', 100);
            $table->string('shape_snapshot', 100)->nullable();
            $table->string('color_snapshot', 100)->nullable();
            $table->string('clarity_snapshot', 100)->nullable();
            $table->string('cut_snapshot', 100)->nullable();
            $table->string('origin_snapshot', 30);
            $table->decimal('total_carat', 10, 3)->comment('carat_snapshot × quantity');
            $table->timestamps();

            $table->unique(['jewellery_item_id', 'stone_id'], 'jewellery_item_stones_item_stone_unique');
        });

        Schema::create('jewellery_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jewellery_item_id')->constrained('jewellery_items')->cascadeOnDelete();
            $table->string('path', 255);
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('jewellery_item_id');
        });

        Schema::create('jewellery_item_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('jewellery_item_id')->constrained('jewellery_items')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('performed_by_role', 30)->nullable();
            $table->timestamps();

            $table->index(['jewellery_item_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jewellery_item_movements');
        Schema::dropIfExists('jewellery_images');
        Schema::dropIfExists('jewellery_item_stones');
        Schema::dropIfExists('jewellery_items');
    }
};
