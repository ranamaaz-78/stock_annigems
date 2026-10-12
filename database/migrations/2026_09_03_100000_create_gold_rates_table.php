<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global gold rates per purity — SuperAdmin managed, no branch_id.
 *
 * Rows are never updated in place. Each rate change inserts a new row so
 * dispute resolution can see exactly what rate applied on a given date.
 * Current rate for a purity = row with the latest effective_date.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gold_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('purity_id')->constrained('purities')->restrictOnDelete();
            $table->decimal('rate_per_gram', 12, 2);
            $table->date('effective_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->timestamps();

            $table->index(['purity_id', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gold_rates');
    }
};
