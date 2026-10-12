<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let an import run in batches.
 *
 * A large sheet is inserted a batch at a time so the browser can show real
 * progress. `chunks_done` is the number of batches already applied, which
 * lets the server refuse a batch sent twice. `finished_at` stays empty until
 * the last step, so an import that was interrupted is visible as such.
 */
return new class extends Migration
{
    /**
     * Add the columns; imports that already exist were single-step, so they
     * count as finished.
     */
    public function up(): void
    {
        Schema::table('stone_imports', function (Blueprint $table): void {
            $table->unsignedInteger('chunks_done')->default(0)->after('rejected_rows');
            $table->timestamp('finished_at')->nullable()->after('rejects_path');
        });

        DB::table('stone_imports')->update(['finished_at' => DB::raw('created_at')]);
    }

    /**
     * Remove the columns.
     */
    public function down(): void
    {
        Schema::table('stone_imports', function (Blueprint $table): void {
            $table->dropColumn(['chunks_done', 'finished_at']);
        });
    }
};
