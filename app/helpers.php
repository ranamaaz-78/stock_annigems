<?php

use App\Services\BranchSettingService;
use App\Services\SettingService;

/**
 * Typed global setting. Falls back to the catalog default when the key
 * has never been saved. Unknown keys return null.
 *
 * Blade, jobs and services must go through this helper (or SettingService)
 * — never Setting::query().
 */
function setting(string $key, mixed $default = null): mixed
{
    $value = app(SettingService::class)->get($key);

    return $value === null && $default !== null ? $default : $value;
}

/**
 * Typed branch setting. $branchId defaults to the authenticated user's
 * branch so a Blade document sheet does not have to thread the id through.
 */
function branch_setting(string $key, ?int $branchId = null, mixed $default = null): mixed
{
    $branchId ??= auth()->user()?->branch_id;

    if ($branchId === null) {
        return $default;
    }

    $value = app(BranchSettingService::class)->get((int) $branchId, $key);

    return $value === null && $default !== null ? $default : $value;
}

/**
 * Format a date with the configured timezone and date_format.
 */
function setting_date(mixed $date, ?string $format = null): string
{
    if ($date === null || $date === '') {
        return '—';
    }

    $carbon = $date instanceof \DateTimeInterface
        ? \Illuminate\Support\Carbon::instance(\Illuminate\Support\Carbon::parse($date))
        : \Illuminate\Support\Carbon::parse($date);

    return $carbon
        ->timezone((string) setting('timezone', 'Asia/Karachi'))
        ->format($format ?? (string) setting('date_format', 'd-m-Y'));
}

/**
 * Date plus time (H:i) in the configured timezone and date_format.
 */
function setting_datetime(mixed $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }

    return setting_date($date, (string) setting('date_format', 'd-m-Y').' H:i');
}

/**
 * Money figure without the currency symbol, using currency_decimals.
 */
function setting_amount(mixed $amount): string
{
    $decimals = max(0, min(4, (int) setting('currency_decimals', 2)));

    return number_format((float) $amount, $decimals);
}

/**
 * Money figure with the configured currency symbol: "Rs 1,240.50".
 */
function setting_money(mixed $amount): string
{
    return trim((string) setting('currency_symbol', 'Rs').' '.setting_amount($amount));
}

/**
 * Scanner helper payload for the existing barcode-scanner.js config object.
 *
 * idle_refocus_ms and the rest of the USB-wedge timings live in
 * config/annigems.php (not SuperAdmin-editable).
 *
 * @return array{inter_keystroke_ms: int, idle_refocus_ms: int, prefix: string, suffix: string, terminators: list<string>, beep_enabled: bool}
 */
function scanner_config(): array
{
    return app(\App\Services\SettingService::class)->scannerConfig();
}

/**
 * Public URL for a stored brand image, or null.
 */
function brand_asset_url(string $key): ?string
{
    return app(\App\Services\BrandImageService::class)->url(setting($key));
}

/**
 * Rows per page for a list, from the "Show" choice under it (?per_page=).
 *
 * Accepts one of the configured sizes or "all" (capped by `all_max`); any
 * other value falls back to the page's own default, so a hand-edited URL can
 * never ask for a page of arbitrary size. Calling this also tells the
 * pagination view that this list honours the choice, so the selector is
 * only drawn where it works.
 *
 * @param  int|null  $default  Size when nothing valid was chosen; the config default if null
 */
function per_page(?int $default = null): int
{
    request()->attributes->set('per_page_enabled', true);

    $raw = request()->query('per_page');

    if ($raw === 'all') {
        return max(1, (int) config('annigems.pagination.all_max', 5000));
    }

    $size = (int) $raw;

    return in_array($size, (array) config('annigems.pagination.options', [10, 20, 50, 100]), true)
        ? $size
        : ($default ?? (int) config('annigems.pagination.default', 20));
}
