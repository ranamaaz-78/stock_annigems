<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the branches table.
 *
 * Branches are the top-level tenant boundary for inventory, sales, customers,
 * and branch users. Soft deletes ensure a branch is never hard-removed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            // Display name shown across SuperAdmin and branch UIs.
            $table->string('name', 150)->unique();
            // Short unique code (e.g. GRW-01) used in reports and barcodes later.
            $table->string('code', 20)->unique();
            $table->text('address');
            $table->string('city', 100);
            $table->string('contact_number', 30);
            // Optional branch contact email; unique among branches when set.
            $table->string('email')->nullable()->unique();
            // Stored as string enum value: active | inactive.
            $table->string('status', 20)->default('active');
            // SuperAdmin who created the branch (nullable if creator was removed).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
