<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Code sequence rows for Module 5 business codes (CUS-, QTN-, INV-).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        foreach (['customer', 'quotation', 'invoice'] as $key) {
            DB::table('code_sequences')->insertOrIgnore([
                'key' => $key,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('code_sequences')->whereIn('key', ['customer', 'quotation', 'invoice'])->delete();
    }
};
