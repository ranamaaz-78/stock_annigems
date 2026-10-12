<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a jewellery stone allocation to record no origin.
 *
 * Stone lots no longer carry a natural/lab origin — the supplier sheet does
 * not state one — so the snapshot has to accept "unknown" rather than force
 * a value that was never recorded. Existing rows keep whatever they stored.
 */
return new class extends Migration
{
    /**
     * Make the origin snapshot optional.
     */
    public function up(): void
    {
        Schema::table('jewellery_item_stones', function (Blueprint $table): void {
            $table->string('origin_snapshot', 30)->nullable()->change();
        });
    }

    /**
     * Restore the NOT NULL constraint, defaulting unknown origins to natural.
     */
    public function down(): void
    {
        Schema::table('jewellery_item_stones', function (Blueprint $table): void {
            $table->string('origin_snapshot', 30)->default('natural')->nullable(false)->change();
        });
    }
};
