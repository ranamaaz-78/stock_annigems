<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weight beside pieces in the stone ledger and in intake.
 *
 * A per-gram lot is stocked by weight, so every movement that changes it
 * records the grams before and after, the same way pieces already do. A
 * movement that only changes weight leaves the piece columns equal.
 * Intake lines carry the grams received and the batch keeps a gram total.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stone_stock_movements', function (Blueprint $table): void {
            $table->decimal('weight_change', 12, 3)->nullable()->after('quantity_after')
                ->comment('Signed grams; only per-gram lots');
            $table->decimal('weight_before', 12, 3)->nullable()->after('weight_change');
            $table->decimal('weight_after', 12, 3)->nullable()->after('weight_before');
        });

        Schema::table('stone_intake_items', function (Blueprint $table): void {
            $table->decimal('weight_grams', 12, 3)->nullable()->after('quantity')
                ->comment('Grams received; per-gram lots only, quantity is 0 for them');
        });

        Schema::table('stone_intakes', function (Blueprint $table): void {
            $table->decimal('total_weight_grams', 14, 3)->default(0)->after('total_carat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stone_intakes', function (Blueprint $table): void {
            $table->dropColumn('total_weight_grams');
        });

        Schema::table('stone_intake_items', function (Blueprint $table): void {
            $table->dropColumn('weight_grams');
        });

        Schema::table('stone_stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['weight_change', 'weight_before', 'weight_after']);
        });
    }
};
