<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stone cost snapshot on each allocation.
 *
 * When a stone lot is put into a piece, the rate it was bought at (in the
 * unit the lot is priced in) and the resulting cost are kept here, together
 * with the grams used for a per-gram lot. Written only by
 * JewelleryStoneAllocationService; later edits to the lot's rate do not
 * change pieces that already exist. Rows made before this column existed
 * keep a zero cost and no mode.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('jewellery_item_stones', function (Blueprint $table): void {
            $table->string('pricing_mode', 20)->nullable()->after('quantity')
                ->comment('per_carat | per_piece | per_gram of the lot when allocated');
            $table->decimal('unit_rate', 14, 3)->default(0)->after('pricing_mode')
                ->comment('Lot rate per carat, piece or gram at allocation');
            $table->decimal('weight_grams', 10, 3)->nullable()->after('unit_rate')
                ->comment('Grams used; per-gram lots only');
            $table->decimal('line_cost', 14, 3)->default(0)->after('weight_grams')
                ->comment('Cost of this allocation at the snapshot rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jewellery_item_stones', function (Blueprint $table): void {
            $table->dropColumn(['pricing_mode', 'unit_rate', 'weight_grams', 'line_cost']);
        });
    }
};
