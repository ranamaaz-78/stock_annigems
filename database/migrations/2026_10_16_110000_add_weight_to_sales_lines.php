<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grams on sales lines, for stone lots priced per gram.
 *
 * A piece or carat lot is sold by the piece, so its line keeps `quantity`. A
 * per-gram lot has no piece count: its line keeps `weight_grams` (quantity
 * stays 1) and the line's per-piece price field then holds the rate per gram.
 * Null `weight_grams` means an ordinary line, so every existing row is unchanged.
 *
 * A performa also tracks how many grams were issued, settled and returned, so
 * a creditor can pay for or give back part of a weighed line, the way a piece
 * line allows part of its pieces.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['quotation_items', 'invoice_items', 'delivery_note_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('weight_grams', 12, 3)->nullable()->after('quantity')
                    ->comment('Grams sold on a per-gram stone line; null on any other line');
            });
        }

        Schema::table('performa_items', function (Blueprint $table): void {
            $table->decimal('weight_issued', 12, 3)->default(0)->after('quantity_returned');
            $table->decimal('weight_settled', 12, 3)->default(0)->after('weight_issued');
            $table->decimal('weight_returned', 12, 3)->default(0)->after('weight_settled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performa_items', function (Blueprint $table): void {
            $table->dropColumn(['weight_issued', 'weight_settled', 'weight_returned']);
        });

        foreach (['delivery_note_items', 'invoice_items', 'quotation_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('weight_grams');
            });
        }
    }
};
