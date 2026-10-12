<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit non-negative check on stones.quantity (unsigned already helps).
 *
 * SQLite in tests does not support ALTER TABLE ADD CONSTRAINT; the
 * StoneStockService still rejects after < 0 there.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            DB::statement('ALTER TABLE stones ADD CONSTRAINT stones_quantity_non_negative CHECK (quantity >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE stones DROP CHECK stones_quantity_non_negative');
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stones DROP CONSTRAINT IF EXISTS stones_quantity_non_negative');
        }
    }
};
