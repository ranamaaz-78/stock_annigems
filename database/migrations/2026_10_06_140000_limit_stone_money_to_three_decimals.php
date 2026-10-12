<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stone rates and costs keep at most three decimals, like carat and grams.
 *
 * Existing figures are rounded to three places first, then the columns are
 * narrowed. SQLite (the test database) does not enforce a scale, so only
 * MySQL / MariaDB need the column change.
 */
return new class extends Migration
{
    /**
     * Column => definition after the change.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'price_per_carat' => 'DECIMAL(14,3) NULL',
        'price_per_piece' => 'DECIMAL(14,3) NULL',
        'price_per_gram' => 'DECIMAL(14,3) NULL',
        'last_rate' => 'DECIMAL(14,3) NULL',
        'piece_price' => 'DECIMAL(14,3) NULL',
        'total_cost' => 'DECIMAL(16,3) NOT NULL DEFAULT 0',
    ];

    /**
     * Round what is stored, then narrow the columns.
     */
    public function up(): void
    {
        foreach (array_keys(self::COLUMNS) as $column) {
            if (Schema::hasColumn('stones', $column)) {
                DB::table('stones')->whereNotNull($column)->update([$column => DB::raw("ROUND({$column}, 3)")]);
            }
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $column => $definition) {
            if (Schema::hasColumn('stones', $column)) {
                DB::statement("ALTER TABLE stones MODIFY {$column} {$definition}");
            }
        }
    }

    /**
     * Widen the columns back to four decimals. Rounded figures stay rounded.
     */
    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $column => $definition) {
            if (Schema::hasColumn('stones', $column)) {
                DB::statement("ALTER TABLE stones MODIFY {$column} ".str_replace(['(14,3)', '(16,3)'], ['(14,4)', '(16,4)'], $definition));
            }
        }
    }
};
