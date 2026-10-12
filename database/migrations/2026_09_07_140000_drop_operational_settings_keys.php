<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop operational keys that are no longer SuperAdmin-editable.
 *
 * Scanner, security, gold-rate warning and inventory thresholds now live
 * as fixed values in config/annigems.php. History rows are left in place
 * so past changes stay auditable.
 */
return new class extends Migration
{
    /**
     * Keys that left the settings catalog.
     *
     * @var list<string>
     */
    private array $retiredKeys = [
        'gold_rate_change_warning_threshold',
        'stone_low_stock_threshold',
        'aging_stock_buckets',
        'scanner_keystroke_threshold_ms',
        'scanner_prefix',
        'scanner_suffix',
        'scanner_terminator',
        'scanner_beep_enabled',
        'session_timeout_minutes',
        'password_min_length',
        'password_require_mixed',
        'login_max_attempts',
        'login_lockout_minutes',
    ];

    /**
     * Remove the retired rows from the live settings bag.
     */
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', $this->retiredKeys)->delete();
    }

    /**
     * Rows are not restored — catalog defaults no longer exist for these keys.
     */
    public function down(): void
    {
        // Intentionally empty: these keys were removed from SettingCatalog.
    }
};
