<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * ReportDatePreset
 *
 * The quick date ranges offered in every report filter bar. Presets exist so
 * the common cases need no typing, but each one still resolves to a concrete
 * from/to pair — the services only ever see explicit dates, never a preset.
 */
enum ReportDatePreset: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case THIS_WEEK = 'this_week';
    case THIS_MONTH = 'this_month';
    case LAST_MONTH = 'last_month';
    case THIS_YEAR = 'this_year';
    case CUSTOM = 'custom';

    /**
     * Human-readable label for the filter bar.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::TODAY => 'Today',
            self::YESTERDAY => 'Yesterday',
            self::THIS_WEEK => 'This week',
            self::THIS_MONTH => 'This month',
            self::LAST_MONTH => 'Last month',
            self::THIS_YEAR => 'This year',
            self::CUSTOM => 'Custom range',
        });
    }

    /**
     * Resolve the preset to a concrete inclusive date range.
     *
     * CUSTOM has no range of its own — the caller supplies the dates — so it
     * falls back to the current month to keep the return type total.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} Start and end of day
     */
    public function range(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now(config('annigems.timezone')))->startOfDay();

        return match ($this) {
            self::TODAY => [$now, $now->endOfDay()],
            self::YESTERDAY => [$now->subDay(), $now->subDay()->endOfDay()],
            self::THIS_WEEK => [$now->startOfWeek(), $now->endOfWeek()],
            self::THIS_MONTH, self::CUSTOM => [$now->startOfMonth(), $now->endOfMonth()],
            self::LAST_MONTH => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            self::THIS_YEAR => [$now->startOfYear(), $now->endOfYear()],
        };
    }

    /**
     * Preset value => label map for select inputs.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
