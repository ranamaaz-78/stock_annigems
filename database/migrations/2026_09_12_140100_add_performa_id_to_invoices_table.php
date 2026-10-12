<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a completed invoice back to the performa it settled.
 *
 * Nullable: normal quotation / workspace invoices have no performa.
 * Voiding a settlement invoice must reverse performa quantities, not
 * treat the sale as a regular POS deduct.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('performa_id')
                ->nullable()
                ->after('quotation_id')
                ->constrained('performas')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performa_id');
        });
    }
};
