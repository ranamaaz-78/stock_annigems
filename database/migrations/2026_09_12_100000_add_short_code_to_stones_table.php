<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-entered 3-letter packet short code (e.g. DIA).
 *
 * Nullable so stones registered before this column keep working.
 * Unique per branch among non-null values — MySQL treats NULL as distinct,
 * so legacy rows do not collide. Soft-delete clears short_code so the
 * letters can be reused on a new registration.
 */
return new class extends Migration
{
    /**
     * Add short_code and a per-branch unique index.
     */
    public function up(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->char('short_code', 3)
                ->nullable()
                ->after('barcode')
                ->comment('Operator 3-letter uppercase lookup code');

            $table->unique(['branch_id', 'short_code'], 'stones_branch_short_code_unique');
        });
    }

    /**
     * Drop the short-code column and its unique index.
     */
    public function down(): void
    {
        Schema::table('stones', function (Blueprint $table): void {
            $table->dropUnique('stones_branch_short_code_unique');
            $table->dropColumn('short_code');
        });
    }
};
