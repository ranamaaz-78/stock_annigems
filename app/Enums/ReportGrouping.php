<?php

namespace App\Enums;

/**
 * ReportGrouping
 *
 * Time bucket a trend report aggregates by. The SQL date expression lives here
 * rather than in each repository so the grouping can never be built from a raw
 * request value — the enum is the only source of the expression string.
 */
enum ReportGrouping: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::DAY => 'Daily',
            self::WEEK => 'Weekly',
            self::MONTH => 'Monthly',
        };
    }

    /**
     * SQL expression that reduces a date column to this bucket.
     *
     * Written with the portable ANSI-ish functions both MySQL (production) and
     * SQLite (test suite) understand, so report tests exercise the same SQL the
     * live system runs.
     *
     * @param  string  $column Fully-qualified, developer-supplied column name.
     *                         Never interpolate a request value here.
     */
    public function sqlExpression(string $column): string
    {
        $driver = config('database.default');
        $isSqlite = config("database.connections.{$driver}.driver") === 'sqlite';

        if ($isSqlite) {
            return match ($this) {
                // SQLite has no DATE_FORMAT; strftime covers all three buckets.
                self::DAY => "strftime('%Y-%m-%d', {$column})",
                // %W is the week number; pairing it with the year keeps buckets
                // unique across a year boundary.
                self::WEEK => "strftime('%Y-W%W', {$column})",
                self::MONTH => "strftime('%Y-%m', {$column})",
            };
        }

        return match ($this) {
            self::DAY => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            self::WEEK => "DATE_FORMAT({$column}, '%x-W%v')",
            self::MONTH => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * Grouping value => label map for select inputs.
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
