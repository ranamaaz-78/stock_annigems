<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-issue every stone packet barcode as a unique six digit number.
 *
 * New stones already get six digits (StoneBarcodeGenerator); this brings the
 * lots registered earlier, with 10-11 digit barcodes, to the same format.
 * Soft-deleted rows are renumbered too, and every number is checked against
 * all of them, because the unique index covers trashed rows as well.
 *
 * Labels printed with the old numbers stop scanning and have to be printed
 * again. The old numbers are not kept anywhere.
 */
return new class extends Migration
{
    /**
     * Give each stone whose barcode is not six digits a fresh six digit one.
     */
    public function up(): void
    {
        $taken = DB::table('stones')->whereNotNull('barcode')->pluck('barcode')->flip()->all();

        $rows = DB::table('stones')
            ->whereNotNull('barcode')
            ->whereRaw('CHAR_LENGTH(barcode) <> 6')
            ->orderBy('id')
            ->pluck('id');

        if (count($taken) + $rows->count() > 800000) {
            throw new RuntimeException('Too many stones for six digit barcodes; widen the format first.');
        }

        // The old numbers are released as they are replaced, but none of them
        // is six digits long, so they can never collide with a new one.
        foreach ($rows->chunk(500) as $chunk) {
            DB::transaction(function () use ($chunk, &$taken): void {
                foreach ($chunk as $id) {
                    do {
                        $candidate = (string) random_int(100000, 999999);
                    } while (isset($taken[$candidate]));

                    $taken[$candidate] = true;

                    DB::table('stones')->where('id', $id)->update(['barcode' => $candidate]);
                }
            });
        }
    }

    /**
     * The old numbers are gone; there is nothing to restore.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
