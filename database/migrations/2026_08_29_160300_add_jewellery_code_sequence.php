<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sequence row for jewellery item codes (JWL-000125).
 *
 * CodeSequenceService also inserts the key on first use; this seed keeps
 * production and tests aligned with stone / intake sequences.
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
            'key' => 'jewellery',
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
        DB::table('code_sequences')->where('key', 'jewellery')->delete();
    }
};
