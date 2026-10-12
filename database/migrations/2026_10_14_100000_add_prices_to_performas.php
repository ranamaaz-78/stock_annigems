<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional prices on a performa.
 *
 * A performa stays a quantity ledger: stock and the settlement maths still run
 * on quantities, and invoices own the money. This column only lets the printed
 * document show what each line was worth when it was issued.
 *
 *   performa_items.unit_price  one piece (jewellery: the whole piece) at issue
 *
 * Rows made before this column existed keep a zero price, and a performa with
 * no priced line prints without the price columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('performa_items', function (Blueprint $table): void {
            $table->decimal('unit_price', 14, 2)->default(0)->after('quantity_returned')
                ->comment('Worth of one piece when issued; 0 = not priced');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performa_items', function (Blueprint $table): void {
            $table->dropColumn('unit_price');
        });
    }
};
