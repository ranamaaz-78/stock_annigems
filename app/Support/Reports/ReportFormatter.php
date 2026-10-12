<?php

namespace App\Support\Reports;

use App\Enums\UserStatus;
use App\Support\AttributionFormatter;

/**
 * ReportFormatter
 *
 * Single place where a raw aggregate becomes a display string. Reports, KPI
 * cards, charts, CSV exports and the print view all format through here, so a
 * figure reads identically wherever it appears — and the currency symbol comes
 * from config rather than being typed into a view.
 *
 * Every money method deliberately says "revenue" in the surrounding labels, not
 * "profit". Purchase cost is not captured by the system, so no margin figure
 * can honestly be produced; see the module spec.
 */
class ReportFormatter
{
    /**
     * Currency amount with the configured symbol: "Rs 1,240,500.00".
     */
    public static function money(float|int|string|null $amount): string
    {
        return setting_money($amount);
    }

    /**
     * Currency amount without decimals, for compact KPI cards.
     */
    public static function moneyCompact(float|int|string|null $amount): string
    {
        return trim((string) setting('currency_symbol', 'Rs').' '.number_format((float) $amount));
    }

    /**
     * Metal weight in grams: "1,204.350 g".
     *
     * Three decimals because gold is weighed and priced to the milligram.
     */
    public static function grams(float|int|string|null $weight): string
    {
        return number_format((float) $weight, 3).' g';
    }

    /**
     * Stone weight in carats: "12.75 ct".
     */
    public static function carats(float|int|string|null $carats): string
    {
        return number_format((float) $carats, 2).' ct';
    }

    /**
     * Whole-number count with thousands separators.
     */
    public static function count(float|int|string|null $count): string
    {
        return number_format((float) $count);
    }

    /**
     * A duration in days: "3.5 days", or "1 day" when exactly one.
     *
     * Fractional because a same-day quotation conversion is real information
     * that must not round away to zero.
     */
    public static function days(float|int|string|null $days): string
    {
        $value = round((float) $days, 2);

        return number_format($value, $value === floor($value) ? 0 : 2).($value === 1.0 ? ' day' : ' days');
    }

    /**
     * Percentage to one decimal: "62.5%".
     */
    public static function percent(float|int|string|null $value): string
    {
        return number_format((float) $value, 1).'%';
    }

    /**
     * Period-over-period change as a signed percentage plus a direction.
     *
     * Returns null when the previous period was zero: "infinite growth" is not
     * a meaningful figure to put on a card, and showing nothing is honest.
     *
     * @return ?array{direction: string, label: string}
     */
    public static function trend(float|int|null $current, float|int|null $previous): ?array
    {
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous === 0.0) {
            return null;
        }

        $change = ($current - $previous) / abs($previous) * 100;
        $rounded = round($change, 1);

        // A change under 0.05% rounds to zero and is reported as flat rather
        // than as a misleading "+0.0% up".
        $direction = match (true) {
            $rounded > 0 => 'up',
            $rounded < 0 => 'down',
            default => 'flat',
        };

        $sign = $rounded > 0 ? '+' : '';

        return [
            'direction' => $direction,
            'label' => $sign.number_format($rounded, 1).'%',
        ];
    }

    /**
     * Render an actor for a report row from the columns a report query selects.
     *
     * Report queries aggregate in SQL and select loose columns rather than
     * hydrating User models, so AttributionFormatter's model-based helpers do
     * not fit. The important behaviour is preserved exactly: the role shown is
     * the *stored* snapshot from the record, so a cashier later promoted still
     * reads as the cashier who made the sale, and a deactivated or deleted user
     * is marked "(inactive)" rather than quietly disappearing.
     *
     * @param  ?string  $name users.name, null when the row is gone or system-written
     * @param  ?string  $roleSnapshot the *_by_role column on the record
     * @param  ?string  $status users.status
     * @param  ?string  $deletedAt users.deleted_at
     */
    public static function actor(?string $name, ?string $roleSnapshot, ?string $status = null, ?string $deletedAt = null): string
    {
        if ($roleSnapshot === AttributionFormatter::SYSTEM_ROLE) {
            return AttributionFormatter::SYSTEM_NAME;
        }

        $display = $name !== null && $name !== ''
            ? $name
            : ($roleSnapshot === null || $roleSnapshot === ''
                ? AttributionFormatter::SYSTEM_NAME
                : AttributionFormatter::FALLBACK_NAME);

        $role = AttributionFormatter::roleLabel($roleSnapshot);

        if ($role !== '') {
            $display .= ' ('.$role.')';
        }

        // A soft-deleted or deactivated user is still the person who acted; the
        // marker says the account is gone without erasing the attribution.
        if ($deletedAt !== null || ($status !== null && $status !== UserStatus::ACTIVE->value)) {
            $display .= ' (inactive)';
        }

        return $display;
    }

    /**
     * Format one table cell according to a column's declared format.
     *
     * Used by the shared report table and by the CSV writer so an exported
     * value matches the value shown on screen exactly.
     */
    public static function cell(mixed $value, ?string $format): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($format) {
            'money' => self::money($value),
            'money_compact' => self::moneyCompact($value),
            'grams' => self::grams($value),
            'carats' => self::carats($value),
            'count' => self::count($value),
            'percent' => self::percent($value),
            'days' => self::days($value),
            'datetime' => AttributionFormatter::timestamp($value),
            default => (string) $value,
        };
    }

    /**
     * Configured currency symbol.
     */
    private static function currencySymbol(): string
    {
        return (string) setting('currency_symbol', config('annigems.currency_symbol', 'Rs'));
    }
}
