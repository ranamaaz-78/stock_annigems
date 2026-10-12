<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creditors — branch-scoped parties who take goods on performa (credit).
 *
 * Separate from sales customers. A performa is always issued against a
 * creditor registered here; walk-in / sales-customer attach is not used.
 *
 * Phone uniqueness is per branch so the same dealer number can exist at
 * another branch without colliding.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('creditors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('creditor_code', 20)->unique()->comment('System-wide CRD-000125');
            $table->string('name', 150);
            $table->string('phone', 30);
            $table->string('alternate_phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 2000)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('status')->default(true)->comment('false = deactivated; hidden from the performa picker');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'phone']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creditors');
    }
};
