<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global jewellery catalogs: categories, sub-categories, metal types, purities.
 *
 * Shared by every branch so "Gold Rings" and "22K" cannot diverge per counter.
 * is_active is the spec's status boolean (active / inactive). Deactivating
 * hides a value from new item forms; existing pieces keep their FKs.
 *
 * No price, cost, rate, or valuation columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('slug', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->comment('Spec status boolean: active / inactive');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('name');
            $table->unique('slug');
        });

        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['category_id', 'name'], 'sub_categories_parent_name_unique');
            $table->unique(['category_id', 'slug'], 'sub_categories_parent_slug_unique');
        });

        Schema::create('metal_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('name');
        });

        Schema::create('purities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('metal_type_id')->constrained('metal_types')->restrictOnDelete();
            $table->string('name', 50);
            // Fineness ratio for the future Quotation module. Unused in Module 4.
            $table->decimal('value', 6, 4)->comment('Fineness e.g. 0.9166 for 22K; stored, not used here');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['metal_type_id', 'name'], 'purities_metal_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purities');
        Schema::dropIfExists('metal_types');
        Schema::dropIfExists('sub_categories');
        Schema::dropIfExists('categories');
    }
};
