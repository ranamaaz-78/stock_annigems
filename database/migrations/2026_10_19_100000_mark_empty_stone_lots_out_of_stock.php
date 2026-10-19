<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A lot with nothing left reads "Out of stock". From now on StoneStockService
 * keeps that in step; this brings the lots that already ran out in line.
 * Pending lots (figures still missing) are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('stones')
            ->where('status', 'in_stock')
            ->where(function ($lot): void {
                $lot->where(fn ($pieces) => $pieces->where('pricing_mode', '!=', 'per_gram')->where('quantity', '<=', 0))
                    ->orWhere(fn ($grams) => $grams->where('pricing_mode', 'per_gram')
                        ->where(fn ($w) => $w->whereNull('weight_grams')->orWhere('weight_grams', '<=', 0)));
            })
            ->update(['status' => 'out_of_stock']);
    }

    public function down(): void
    {
        DB::table('stones')->where('status', 'out_of_stock')->update(['status' => 'in_stock']);
    }
};
