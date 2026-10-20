<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-issue every jewellery barcode as a unique six digit number.
 *
 * Until now a piece's barcode was its own JWL- item code. It becomes a random
 * six digit number, like a stone's, so one label design and one scan format
 * serve both. Numbers are checked against stones too, because the till scans
 * one field for both. The item code itself is untouched.
 *
 * Labels printed with the old barcode stop scanning and must be printed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $taken = DB::table('stones')->whereNotNull('barcode')->pluck('barcode')
            ->merge(DB::table('jewellery_items')->whereNotNull('barcode')->pluck('barcode'))
            ->flip()->all();

        $ids = DB::table('jewellery_items')
            ->whereNotNull('barcode')
            ->whereRaw('LENGTH(barcode) <> 6')
            ->orderBy('id')
            ->pluck('id');

        DB::transaction(function () use ($ids, &$taken): void {
            foreach ($ids as $id) {
                do {
                    $candidate = (string) random_int(100000, 999999);
                } while (isset($taken[$candidate]));

                $taken[$candidate] = true;

                DB::table('jewellery_items')->where('id', $id)->update(['barcode' => $candidate]);
            }
        });
    }

    public function down(): void
    {
        // The old numbers are not kept; there is nothing to restore.
    }
};
