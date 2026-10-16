<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spanish tax identity (NIE / NIF) on customers and creditors.
 *
 * Optional everywhere: nothing requires it, and it is neither unique nor
 * validated against a checksum, because the same person can be entered by
 * different branches and a mistyped one must not block a sale.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['customers', 'creditors'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('nie', 20)->nullable()->after('email')->comment('NIE / NIF, optional');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['customers', 'creditors'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('nie');
            });
        }
    }
};
