<?php

namespace App\Support\Settings;

/**
 * RuntimeSettings
 *
 * Pushes the live settings bag onto Laravel config so leftover
 * config('annigems.timezone') / config('app.name') reads pick up
 * the SuperAdmin company and locale values.
 *
 * Scanner, password, login lockout, session idle, gold-rate warning and
 * inventory thresholds are fixed in config/annigems.php — they are not
 * SuperAdmin-editable.
 *
 * Called on every web request from ApplySettingsSession, and at the start
 * of queued jobs that format dates or money.
 */
class RuntimeSettings
{
    /**
     * Overlay config() with the current settings bag.
     *
     * Safe to call before the settings table exists — SettingService
     * returns catalog defaults in that case.
     */
    public static function apply(): void
    {
        $timezone = (string) setting('timezone', 'Asia/Karachi');
        $company = (string) setting('company_name', 'Aanigemas');
        $symbol = (string) setting('currency_symbol', 'Rs');
        $code = strtoupper((string) setting('currency_code', 'PKR'));

        if ($timezone !== '' && @timezone_open($timezone) !== false) {
            date_default_timezone_set($timezone);
            config(['app.timezone' => $timezone]);
        }

        config([
            'app.name' => $company !== '' ? $company : 'Aanigemas',
            'annigems.timezone' => $timezone,
            'annigems.currency' => $code !== '' ? $code : 'PKR',
            'annigems.currency_symbol' => $symbol !== '' ? $symbol : 'Rs',
            'annigems.documents.brand_name' => $company !== '' ? $company : 'Aanigemas',
        ]);
    }
}
