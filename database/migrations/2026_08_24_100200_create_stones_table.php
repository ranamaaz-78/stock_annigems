<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-scoped stone types (quantity-based inventory).
 *
 * Branch scoping: each branch registers and barcodes its own stones and
 * holds its own quantity. Two branches stocking the same physical type
 * therefore have two registrations and two barcodes. That is intentional
 * and consistent with jewellery items. If cross-branch stone transfers
 * are added later, the catalog would need splitting from per-branch stock.
 *
 * Quantity: default 0. NEVER written by controllers or casual updates —
 * StoneStockService is the only writer, and it always records a movement.
 *
 * Identity: MySQL unique indexes treat NULLs as distinct, so a composite
 * unique on nullable FKs would not block duplicates. attribute_fingerprint
 * is a SHA-256 of the canonical attribute set (empty placeholders for
 * nulls, carat at 3 decimals). Unique on (branch_id, attribute_fingerprint).
 * Soft-delete rewrites the fingerprint so the combination can be registered
 * again.
 *
 * Carat is the weight (1 ct = 0.2 g). There is no separate grams column.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('stone_code', 20)->unique()->comment('System-wide STN-000125; never from input');
            $table->string('barcode', 64)->unique()->comment('Code 128 payload; encodes stone_code');
            $table->string('name', 150);
            $table->foreignId('stone_type_id')->constrained('stone_types')->restrictOnDelete();
            $table->foreignId('stone_shape_id')->nullable()->constrained('stone_shapes')->restrictOnDelete();
            $table->foreignId('stone_color_id')->nullable()->constrained('stone_colors')->restrictOnDelete();
            $table->foreignId('stone_clarity_id')->nullable()->constrained('stone_clarities')->restrictOnDelete();
            $table->foreignId('stone_cut_id')->nullable()->constrained('stone_cuts')->restrictOnDelete();
            $table->string('origin', 30);
            $table->decimal('carat', 8, 3)->comment('Weight in carats; the only mass field');
            $table->unsignedInteger('quantity')->default(0)->comment('Written only by StoneStockService');
            $table->string('attribute_fingerprint', 64);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'attribute_fingerprint'], 'stones_branch_fingerprint_unique');
            $table->index(['branch_id', 'is_active']);
            $table->index('stone_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stones');
    }
};
