<?php

namespace App\Repositories;

use App\Models\GoldRate;
use App\Models\Purity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GoldRateRepository
 *
 * Read queries for gold rate history and current-rate resolution. Writes
 * happen in GoldRateService (insert-only).
 */
class GoldRateRepository
{
    /**
     * Latest rate row per active purity (by max effective_date).
     *
     * @return Collection<int, array{purity: Purity, rate: GoldRate|null}>
     */
    public function currentRatesForActivePurities(): Collection
    {
        $purities = Purity::query()
            ->with('metalType')
            ->active()
            ->ordered()
            ->get();

        $latestIds = GoldRate::query()
            ->select('purity_id', DB::raw('MAX(effective_date) as max_date'))
            ->groupBy('purity_id');

        $rates = GoldRate::query()
            ->joinSub($latestIds, 'latest', function ($join): void {
                $join->on('gold_rates.purity_id', '=', 'latest.purity_id')
                    ->on('gold_rates.effective_date', '=', 'latest.max_date');
            })
            ->with('creator')
            ->get()
            ->keyBy('purity_id');

        return $purities->map(fn (Purity $purity): array => [
            'purity' => $purity,
            'rate' => $rates->get($purity->id),
        ]);
    }

    /**
     * Current rate for one purity, or null when never set.
     */
    public function currentRateForPurity(int $purityId): ?GoldRate
    {
        return GoldRate::query()
            ->where('purity_id', $purityId)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Paginated history with optional filters.
     *
     * @param  array{from?: string, to?: string, purity_id?: int|string}  $filters
     */
    public function history(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = GoldRate::query()
            ->with(['purity.metalType', 'creator'])
            ->orderByDesc('effective_date')
            ->orderByDesc('id');

        if (! empty($filters['from'])) {
            $query->whereDate('effective_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('effective_date', '<=', $filters['to']);
        }

        if (! empty($filters['purity_id'])) {
            $query->where('purity_id', (int) $filters['purity_id']);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Map purity_id => current rate_per_gram for quotation defaults.
     *
     * @return array<int, string>
     */
    public function currentRateMap(): array
    {
        $map = [];

        foreach ($this->currentRatesForActivePurities() as $row) {
            if ($row['rate']) {
                $map[$row['purity']->id] = (string) $row['rate']->rate_per_gram;
            }
        }

        return $map;
    }
}
