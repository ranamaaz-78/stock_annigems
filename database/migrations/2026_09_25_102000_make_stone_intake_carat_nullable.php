<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow an intake line to record no carat.
 *
 * Only per-carat lots carry a carat weight; per-piece and per-gram lots do
 * not, so the snapshot has to be able to say "none" rather than lie with a
 * zero that would then be summed into the intake's total carat.
 */
return new class extends Migration
{
    /**
     * Make the carat snapshot optional.
     */
    public function up(): void
    {
        Schema::table('stone_intake_items', function (Blueprint $table): void {
            $table->decimal('carat_snapshot', 10, 3)->nullable()->change();
        });
    }

    /**
     * Restore the NOT NULL constraint, treating missing carats as zero.
     */
    public function down(): void
    {
        Schema::table('stone_intake_items', function (Blueprint $table): void {
            $table->decimal('carat_snapshot', 10, 3)->default(0)->nullable(false)->change();
        });
    }
};
