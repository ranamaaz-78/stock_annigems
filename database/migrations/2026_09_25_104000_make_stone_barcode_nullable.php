<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a lot to hold no packet barcode.
 *
 * The stone_barcode_enabled setting lets a branch skip packet labels
 * entirely. The barcode column has to be able to say "none issued" so a
 * disabled toggle cannot be worked around by falling back to the STN code.
 * The unique index still applies to the barcodes that do exist.
 */
return new class extends Migration
{
    /**
     * Make the packet barcode optional.
     */
    public function up(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable()->change();
        });
    }

    /**
     * Restore the NOT NULL constraint.
     *
     * Lots registered without a barcode would block this, which is the
     * correct outcome: there is no value to invent for them.
     */
    public function down(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable(false)->change();
        });
    }
};
