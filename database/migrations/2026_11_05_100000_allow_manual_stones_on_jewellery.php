<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a jewellery piece carry stones that are not in the stone list.
 *
 * A piece bought with its stones already set has no matching lot, so its
 * stones are typed straight onto the piece. Such a row keeps no stone_id: it
 * is a description only (name, shape, size, carat, quantity) and moves no
 * stock. Every row made so far keeps the lot it was allocated from.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('jewellery_item_stones', function (Blueprint $table): void {
            $table->unsignedBigInteger('stone_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Typed-in rows have no lot to point at, so they are removed first.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('jewellery_item_stones')->whereNull('stone_id')->delete();

        Schema::table('jewellery_item_stones', function (Blueprint $table): void {
            $table->unsignedBigInteger('stone_id')->nullable(false)->change();
        });
    }
};
