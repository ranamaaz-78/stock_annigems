<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers — deliberately GLOBAL (no branch_id).
 *
 * Every other business table in Annigems is branch-scoped. Customers are the
 * intentional exception: one person must not be duplicated across branches,
 * and purchase history spans all branches. Do NOT add branch_id here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('customer_code', 20)->unique()->comment('System-wide CUS-000125');
            $table->string('name', 150);
            $table->string('phone', 30)->unique()->comment('Unique system-wide, not per branch');
            $table->string('alternate_phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('cnic', 20)->nullable()->unique();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable()->comment('Internal staff notes');
            $table->boolean('status')->default(true)->comment('Active / inactive');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
