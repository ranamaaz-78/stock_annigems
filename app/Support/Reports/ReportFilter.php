<?php

namespace App\Support\Reports;

use App\Enums\ReportDatePreset;
use App\Enums\ReportGrouping;
use Carbon\CarbonImmutable;

/**
 * ReportFilter
 *
 * Immutable value object describing exactly which slice of data a report may
 * read. Every report service and every export takes one of these and nothing
 * else, which is what keeps a screen and its export in agreement.
 *
 * Security note: $branchId is resolved from the authenticated user by
 * ReportFilterRequest, never read from the request payload. A branch Admin
 * cannot widen its own scope by editing a hidden field or a query string.
 * SuperAdmin is the only actor whose branch is chosen, and that choice is made
 * through the dedicated SuperAdmin filter request.
 *
 * The $options bag holds already-resolved integer ids (uuids are translated to
 * ids during validation) plus scalar enum values. Nothing raw from the request
 * reaches a query.
 */
final class ReportFilter
{
    /**
     * @param  CarbonImmutable  $from Inclusive start, at start of day
     * @param  CarbonImmutable  $to Inclusive end, at end of day
     * @param  ?int  $branchId Null means "all branches" (SuperAdmin only)
     * @param  array<string, mixed>  $options Resolved secondary filters
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ReportDatePreset $preset,
        public readonly ReportGrouping $grouping,
        public readonly ?int $branchId,
        public readonly array $options = [],
    ) {}

    /**
     * Default filter for a branch: the current month, grouped by day.
     *
     * Used when a report screen is opened without any query string, so the page
     * always renders real figures rather than an empty prompt.
     */
    public static function currentMonth(?int $branchId): self
    {
        [$from, $to] = ReportDatePreset::THIS_MONTH->range();

        return new self($from, $to, ReportDatePreset::THIS_MONTH, ReportGrouping::DAY, $branchId);
    }

    /**
     * Read a resolved secondary filter.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Whether a secondary filter was supplied and is not empty.
     */
    public function hasOption(string $key): bool
    {
        $value = $this->options[$key] ?? null;

        return $value !== null && $value !== '';
    }

    /**
     * Copy with the branch replaced.
     *
     * Used by the SuperAdmin "All Branches" reports, which run the same
     * aggregate query once per branch to build the per-branch breakdown.
     */
    public function forBranch(?int $branchId): self
    {
        return new self($this->from, $this->to, $this->preset, $this->grouping, $branchId, $this->options);
    }

    /**
     * Copy with a different time bucket.
     */
    public function withGrouping(ReportGrouping $grouping): self
    {
        return new self($this->from, $this->to, $this->preset, $grouping, $this->branchId, $this->options);
    }

    /**
     * Copy shifted back by the length of the current range.
     *
     * This is how every trend comparison ("+12.5% vs previous period") is
     * produced: the same report is run once for the range and once for the
     * immediately preceding range of identical length.
     */
    public function previousPeriod(): self
    {
        $days = $this->from->diffInDays($this->to) + 1;

        return new self(
            $this->from->subDays($days)->startOfDay(),
            $this->to->subDays($days)->endOfDay(),
            ReportDatePreset::CUSTOM,
            $this->grouping,
            $this->branchId,
            $this->options,
        );
    }

    /**
     * Inclusive number of days covered.
     */
    public function days(): int
    {
        return $this->from->diffInDays($this->to) + 1;
    }

    /**
     * Human-readable range for report headers and export headers.
     */
    public function rangeLabel(): string
    {
        if ($this->from->isSameDay($this->to)) {
            return $this->from->format('d M Y');
        }

        return $this->from->format('d M Y').' – '.$this->to->format('d M Y');
    }

    /**
     * Stable representation used for cache keys and for the `filters` json
     * column on an export record.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'preset' => $this->preset->value,
            'grouping' => $this->grouping->value,
            'branch_id' => $this->branchId,
            'options' => $this->options,
        ];
    }

    /**
     * Short deterministic signature of this filter, for cache keys.
     */
    public function signature(): string
    {
        return substr(sha1(json_encode($this->toArray(), JSON_THROW_ON_ERROR)), 0, 16);
    }
}
