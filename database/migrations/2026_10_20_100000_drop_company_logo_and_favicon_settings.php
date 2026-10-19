<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the company logo and favicon settings.
 *
 * Both are fixed files in public/brand/ now, not something SuperAdmin uploads.
 * History rows are left in place so past changes stay auditable.
 */
return new class extends Migration
{
    /**
     * Remove the two rows from the live settings bag.
     */
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', ['company_logo', 'company_favicon'])->delete();
    }

    /**
     * Rows are not restored — these keys are no longer in SettingCatalog.
     */
    public function down(): void
    {
        // Intentionally empty: these keys were removed from SettingCatalog.
    }
};
