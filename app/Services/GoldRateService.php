<?php

namespace App\Services;

use App\Models\GoldRate;
use App\Models\Purity;
use App\Models\User;
use App\Repositories\GoldRateRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GoldRateService
 *
 * Manages global gold rate history. Every rate change inserts a new row —
 * existing rows are never updated. SuperAdmin only for writes.
 */
class GoldRateService
{
    public function __construct(
        private GoldRateRepository $repository,
        private AuditLogService $auditLog,
    ) {}

    /**
     * Current rates for all active purities with their latest row.
     *
     * @return Collection<int, array{purity: Purity, rate: GoldRate|null}>
     */
    public function currentRates(): Collection
    {
        return $this->repository->currentRatesForActivePurities();
    }

    /**
     * Paginated rate history.
     *
     * @param  array{from?: string, to?: string, purity_id?: int|string}  $filters
     */
    public function history(array $filters): LengthAwarePaginator
    {
        return $this->repository->history($filters);
    }

    /**
     * Purity id => rate map for quotation panel defaults.
     *
     * @return array<int, string>
     */
    public function currentRateMap(): array
    {
        return $this->repository->currentRateMap();
    }

    /**
     * Insert new rate rows for purities with supplied values.
     *
     * @param  array<int, float|string>  $ratesByPurityId  purity_id => rate_per_gram
     * @return list<array{purity_id: int, purity_name: string, current: float|null, new: float, deviation_percent: float|null}>
     */
    public function previewDeviations(array $ratesByPurityId, string $effectiveDate): array
    {
        $warnings = [];

        foreach ($ratesByPurityId as $purityId => $newRate) {
            $purity = Purity::query()->find((int) $purityId);
            if (! $purity) {
                continue;
            }

            $current = $this->repository->currentRateForPurity((int) $purityId);
            $currentValue = $current ? (float) $current->rate_per_gram : null;
            $newValue = (float) $newRate;
            $deviation = null;

            if ($currentValue !== null && $currentValue > 0) {
                $deviation = abs(($newValue - $currentValue) / $currentValue) * 100;
            }

            $warnings[] = [
                'purity_id' => (int) $purityId,
                'purity_name' => $purity->name,
                'current' => $currentValue,
                'new' => $newValue,
                'deviation_percent' => $deviation,
            ];
        }

        return $warnings;
    }

    /**
     * Persist new rate rows (insert-only). Wrapped in a transaction with audit.
     *
     * @param  array<int, float|string>  $ratesByPurityId
     */
    public function storeRates(array $ratesByPurityId, string $effectiveDate, User $actor): void
    {
        DB::transaction(function () use ($ratesByPurityId, $effectiveDate, $actor): void {
            foreach ($ratesByPurityId as $purityId => $rate) {
                $purity = Purity::query()->findOrFail((int) $purityId);

                $previous = $this->repository->currentRateForPurity($purity->id);

                $goldRate = GoldRate::query()->create([
                    'purity_id' => $purity->id,
                    'rate_per_gram' => $rate,
                    'effective_date' => $effectiveDate,
                ]);

                $this->auditLog->log(
                    'gold_rate.created',
                    $goldRate,
                    $previous ? ['rate_per_gram' => $previous->rate_per_gram, 'effective_date' => $previous->effective_date?->toDateString()] : null,
                    ['rate_per_gram' => $goldRate->rate_per_gram, 'effective_date' => $goldRate->effective_date->toDateString(), 'purity' => $purity->name],
                    $actor
                );
            }
        });
    }

    /**
     * Validate deviation warnings and throw if any exceed threshold without confirm flag.
     *
     * @param  array<int, float|string>  $ratesByPurityId
     */
    public function assertDeviationAccepted(array $ratesByPurityId, string $effectiveDate, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }

        $threshold = (int) config('annigems.gold_rates.deviation_warn_percent', 20);
        $warnings = $this->previewDeviations($ratesByPurityId, $effectiveDate);

        $offenders = array_filter($warnings, fn (array $row): bool => $row['deviation_percent'] !== null && $row['deviation_percent'] > $threshold);

        if ($offenders !== []) {
            // Name the offending purities so the operator can spot the typo;
            // the error bag only accepts strings, never nested arrays.
            $names = implode(', ', array_map(
                static fn (array $row): string => $row['purity_name'].' ('.number_format($row['deviation_percent'], 1).'%)',
                $offenders
            ));

            throw ValidationException::withMessages([
                'rates' => "These rates differ from the current rate by more than {$threshold}%: {$names}. Confirm the change to save it.",
            ]);
        }
    }
}
