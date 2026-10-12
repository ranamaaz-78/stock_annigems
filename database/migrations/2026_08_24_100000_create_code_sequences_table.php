<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locked numeric sequences for system-wide business codes (STN-000125, SIN-000125).
 *
 * A dedicated table with SELECT … FOR UPDATE avoids collisions when two
 * registrations run at the same moment. Codes are never accepted from input.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('code_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique()->comment('Sequence name, e.g. stone, stone_intake');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('code_sequences')->insert([
            ['key' => 'stone', 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'stone_intake', 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_sequences');
    }
};
