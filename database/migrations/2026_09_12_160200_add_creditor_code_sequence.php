<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Code sequence row for creditor codes (CRD-).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('code_sequences')->insertOrIgnore([
            'key' => 'creditor',
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('code_sequences')->where('key', 'creditor')->delete();
    }
};
