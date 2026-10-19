<?php

namespace App\Services;

use App\Enums\JewelleryItemStatus;
use App\Enums\LineType;
use App\Enums\MakingChargeType;
use App\Enums\PerformaStatus;
use App\Enums\StoneMovementType;
use App\Models\Creditor;
use App\Models\JewelleryItem;
use App\Models\Performa;
use App\Models\PerformaItem;
use App\Models\Stone;
use App\Models\User;
use App\Repositories\GoldRateRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * PerformaService
 *
 * Issues and returns credit ledgers. Stock leaves on issue (jewellery on
 * memo, loose stones ISSUED_ON_CREDIT). Prices are only a printed memo of
 * what each line was worth at issue — settlement invoices own the money.
 */
class PerformaService
{
    public function __construct(
        private CodeSequenceService $codeSequence,
        private SalesLookupService $lookupService,
        private JewelleryItemService $jewelleryItemService,
        private StoneStockService $stoneStockService,
        private AuditLogService $auditLog,
        private GoldRateRepository $goldRateRepository,
        private PricingCalculationService $pricing,
        private CreditorService $creditorService,
    ) {}

    /**
     * Branch-scoped list with outstanding qty summaries.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Performa::query()
            ->with(['creditor', 'creator', 'items'])
            ->withCount('items')
            ->orderByDesc('performa_date')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('performa_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('performa_date', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term): void {
                $q->where('performa_number', 'like', "%{$term}%")
                    ->orWhereHas('creditor', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
            });
        }

        return $query->paginate(per_page())->withQueryString();
    }

    /**
     * Load a performa with lines and settlement invoices.
     */
    public function loadDetail(Performa $performa): Performa
    {
        return $performa->load([
            'creditor',
            'branch',
            'creator',
            'updater',
            'items.jewelleryItem.itemStones',
            'items.jewelleryItem.category',
            'items.jewelleryItem.metalType',
            'items.jewelleryItem.purity',
            'items.stone',
            'settlements.invoice',
            'settlements.creator',
        ]);
    }

    /**
     * Issue a performa: attach creditor, lock stock, write lines, deduct now.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(array $data, User $actor): Performa
    {
        if ((int) $actor->branch_id < 1) {
            abort(403);
        }

        $lines = $this->lookupService->hydrateLines($data['lines'] ?? [], $actor);

        return DB::transaction(function () use ($data, $lines, $actor): Performa {
            $this->assertLinesAvailable($lines, $actor);

            $creditorId = $this->resolveCreditorId($data, $actor);
            if ($creditorId === null) {
                throw ValidationException::withMessages([
                    'creditor_uuid' => 'Select a creditor. Register them under Creditors first.',
                ]);
            }

            $performa = Performa::query()->create([
                'branch_id' => $actor->branch_id,
                'performa_number' => $this->codeSequence->nextPerformaNumber(),
                'creditor_id' => $creditorId,
                'performa_date' => $data['performa_date'] ?? now()->toDateString(),
                'status' => PerformaStatus::OPEN,
                'notes' => $data['notes'] ?? null,
            ]);

            $rates = $this->goldRateRepository->currentRateMap();

            foreach ($lines as $index => $line) {
                $this->writeLine($performa, $line, $index, $this->unitPrice($line, $rates));
                $this->applyIssueStock($line, $performa, $actor);
            }

            $this->auditLog->log('performa.issued', $performa, null, [
                'performa_number' => $performa->performa_number,
                'creditor_id' => $creditorId,
                'lines' => count($lines),
            ], $actor);

            return $this->loadDetail($performa->fresh());
        });
    }

    /**
     * Whether a line has been paid for or sent back in part or in full. Such
     * a line is history and cannot be edited any more.
     */
    private function isTouched(PerformaItem $item): bool
    {
        return $item->quantity_settled > 0
            || $item->quantity_returned > 0
            || (float) $item->weight_settled > 0
            || (float) $item->weight_returned > 0;
    }

    /**
     * Edit an open performa.
     *
     * Lines nobody has paid for or returned are taken back into stock and
     * replaced by the lines sent; lines that already have a settlement or a
     * return are kept as they are (and the creditor stays). Stock only moves
     * for what really changed hands.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Performa $performa, array $data, User $actor): Performa
    {
        if ((int) $performa->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        $lines = $this->lookupService->hydrateLines($data['lines'] ?? [], $actor);

        return DB::transaction(function () use ($performa, $data, $lines, $actor): Performa {
            $performa = Performa::query()->whereKey($performa->id)->lockForUpdate()->with('items')->firstOrFail();

            if (! $performa->status->isOpen()) {
                throw ValidationException::withMessages([
                    'performa' => 'Only an open performa can be edited.',
                ]);
            }

            $kept = $performa->items->filter(fn (PerformaItem $item): bool => $this->isTouched($item));

            // A performa with payments or returns keeps its creditor.
            $creditorId = $kept->isNotEmpty() ? (int) $performa->creditor_id : $this->resolveCreditorId($data, $actor);
            if ($creditorId === null) {
                throw ValidationException::withMessages([
                    'creditor_uuid' => 'Select a creditor. Register them under Creditors first.',
                ]);
            }

            if ($lines === [] && $kept->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Add at least one item to the performa.']);
            }

            // Take the untouched lines back first so their own stock counts as available again.
            foreach ($performa->items->reject(fn (PerformaItem $item): bool => $this->isTouched($item)) as $item) {
                $this->applyReturnStock($item, $item->outstandingAmount(), $performa, $actor);
                $item->delete();
            }

            if ($lines !== []) {
                $this->assertLinesAvailable($lines, $actor);
            }

            $rates = $this->goldRateRepository->currentRateMap();
            $next = (int) $kept->max('sort_order') + 1;

            foreach ($lines as $index => $line) {
                $this->writeLine($performa, $line, $next + $index, $this->unitPrice($line, $rates));
                $this->applyIssueStock($line, $performa, $actor);
            }

            $performa->update([
                'creditor_id' => $creditorId,
                'performa_date' => $data['performa_date'] ?? $performa->performa_date,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->refreshStatus($performa);

            $this->auditLog->log('performa.updated', $performa, null, [
                'performa_number' => $performa->performa_number,
                'lines' => count($lines),
                'kept_lines' => $kept->count(),
            ], $actor);

            return $this->loadDetail($performa->fresh());
        });
    }

    /**
     * What the POS needs to open an issued performa for editing: the header,
     * the lines that can still change (as POS lines, with their own stock
     * counted as available) and the ones locked by a payment or return.
     *
     * @return array<string, mixed>
     */
    public function editPayload(Performa $performa): array
    {
        $performa = $this->loadDetail($performa);
        $locked = [];
        $lines = [];

        foreach ($performa->items as $item) {
            if ($this->isTouched($item)) {
                $locked[] = [
                    'code' => $item->item_code_snapshot,
                    'name' => $item->name_snapshot,
                    'summary' => $item->isWeighed()
                        ? $item->amountLabel($item->weight_settled).' paid, '.$item->amountLabel($item->weight_returned).' returned of '.$item->amountLabel($item->weight_issued)
                        : $item->quantity_settled.' paid, '.$item->quantity_returned.' returned of '.$item->quantity_issued,
                ];

                continue;
            }

            if ($item->line_type === LineType::JEWELLERY_ITEM && $item->jewelleryItem) {
                $lines[] = array_merge($this->lookupService->jewelleryPayload($item->jewelleryItem), [
                    'gold_rate_applied' => (string) ($this->goldRateRepository->currentRateMap()[$item->jewelleryItem->purity_id] ?? '0.00'),
                ]);
            } elseif ($item->stone) {
                $payload = $this->lookupService->stonePayload($item->stone);
                $lines[] = array_merge($payload, [
                    // The line's own stock left the shelf when it was issued: count it as on hand.
                    'available_quantity' => (int) $item->stone->quantity + (int) $item->quantity_issued,
                    'available_grams' => $item->isWeighed()
                        ? number_format((float) $item->stone->weight_grams + (float) $item->weight_issued, 3, '.', '')
                        : null,
                    'quantity' => $item->isWeighed() ? 1 : (int) $item->quantity_issued,
                    'weight_grams' => $item->isWeighed() ? number_format((float) $item->weight_issued, 3, '.', '') : null,
                    'price_per_piece' => number_format((float) $item->unit_price, 2, '.', ''),
                ]);
            }
        }

        $creditor = $performa->creditor;

        return [
            'uuid' => $performa->uuid,
            'number' => $performa->performa_number,
            'performa_date' => $performa->performa_date?->toDateString(),
            'notes' => (string) $performa->notes,
            'creditor_locked' => $locked !== [],
            'creditor' => $creditor ? [
                'uuid' => $creditor->uuid,
                'customer_code' => $creditor->creditor_code,
                'name' => $creditor->name,
                'phone' => $creditor->phone,
                'email' => $creditor->email,
                'nie' => $creditor->nie,
                'address' => $creditor->address,
            ] : null,
            'locked' => $locked,
            'lines' => $lines,
        ];
    }

    /**
     * Return unpaid outstanding qty. Fully returned with no settlements
     * cancels the performa; leftover outstanding stays open/partial.
     *
     * @param  array<string, mixed>  $data
     */
    public function returnUnpaid(Performa $performa, array $data, User $actor): Performa
    {
        if ((int) $performa->branch_id !== (int) $actor->branch_id) {
            abort(403);
        }

        if (! $performa->isOpen()) {
            throw ValidationException::withMessages([
                'performa' => 'This performa has no unpaid goods to return.',
            ]);
        }

        return DB::transaction(function () use ($performa, $data, $actor): Performa {
            $performa = Performa::query()->whereKey($performa->id)->lockForUpdate()->with('items')->firstOrFail();

            if (! $performa->status->isOpen()) {
                throw ValidationException::withMessages([
                    'performa' => 'This performa has no unpaid goods to return.',
                ]);
            }

            $requested = $this->requestedReturnQty($performa, $data);
            $returnedAny = false;

            foreach ($performa->items as $item) {
                $qty = $requested[$item->id] ?? 0;
                if ($qty <= 0) {
                    continue;
                }

                // Pieces come whole; only grams may carry decimals.
                if (! $item->isWeighed() && floor($qty) !== (float) $qty) {
                    throw ValidationException::withMessages([
                        "lines.{$item->id}" => "{$item->item_code_snapshot} is returned in whole pieces.",
                    ]);
                }

                $outstanding = $item->outstandingAmount();
                if ($qty > $outstanding + 0.0005) {
                    $unit = $item->isWeighed() ? ' g' : '';
                    throw ValidationException::withMessages([
                        "lines.{$item->id}" => "{$item->item_code_snapshot} has only {$outstanding}{$unit} unpaid.",
                    ]);
                }

                $this->applyReturnStock($item, $qty, $performa, $actor);
                if ($item->isWeighed()) {
                    $item->weight_returned = round((float) $item->weight_returned + $qty, 3);
                } else {
                    $item->quantity_returned += (int) $qty;
                }
                $item->save();
                $returnedAny = true;
            }

            if (! $returnedAny) {
                throw ValidationException::withMessages([
                    'lines' => 'Select at least one unpaid line to return.',
                ]);
            }

            $this->refreshStatus($performa);

            $this->auditLog->log('performa.returned', $performa, null, [
                'performa_number' => $performa->performa_number,
                'lines' => $requested,
            ], $actor);

            return $this->loadDetail($performa->fresh());
        });
    }

    /**
     * Recalculate header status from line outstanding / settled totals.
     */
    public function refreshStatus(Performa $performa): void
    {
        $performa->load('items');

        $outstanding = 0;
        $settled = 0;

        foreach ($performa->items as $item) {
            $outstanding += $item->outstandingQuantity();
            $settled += $item->settledAmount();
        }

        if ($outstanding === 0) {
            $performa->status = $settled > 0 ? PerformaStatus::SETTLED : PerformaStatus::CANCELLED;
        } elseif ($settled > 0) {
            $performa->status = PerformaStatus::PARTIAL;
        } else {
            $performa->status = PerformaStatus::OPEN;
        }

        $performa->save();
    }

    /**
     * Workspace payload for the create screen (no prices).
     *
     * @return array<string, mixed>
     */
    public function emptyPayload(): array
    {
        return [
            'performa_date' => now()->toDateString(),
            'creditor_uuid' => '',
            'creditor' => null,
            'notes' => '',
            'lines' => [],
        ];
    }

    /**
     * Outstanding lines for the settle screen.
     *
     * @return list<array<string, mixed>>
     */
    public function outstandingPayload(Performa $performa): array
    {
        $performa = $this->loadDetail($performa);

        return $performa->items
            ->filter(fn (PerformaItem $item): bool => $item->outstandingQuantity() > 0)
            ->map(fn (PerformaItem $item): array => $this->outstandingLinePayload($item))
            ->values()
            ->all();
    }

    /**
     * One outstanding line for the settle workspace. Jewellery reuses the
     * live item so weight, purity, and stone composition are available for
     * the same gold-rate / making calculator as quotations.
     *
     * @return array<string, mixed>
     */
    private function outstandingLinePayload(PerformaItem $item): array
    {
        $base = [
            'id' => $item->id,
            'line_type' => $item->line_type->value,
            'item_code_snapshot' => $item->item_code_snapshot,
            'name_snapshot' => $item->name_snapshot,
            'category_snapshot' => $item->category_snapshot,
            'metal_snapshot' => $item->metal_snapshot,
            'purity_snapshot' => $item->purity_snapshot,
            'gross_weight_snapshot' => (string) $item->gross_weight_snapshot,
            'net_weight_snapshot' => (string) $item->net_weight_snapshot,
            'quantity_issued' => $item->quantity_issued,
            'quantity_settled' => $item->quantity_settled,
            'quantity_returned' => $item->quantity_returned,
            // A weighed line is settled and returned in grams.
            'weighed' => $item->isWeighed(),
            'unit' => $item->isWeighed() ? 'g' : 'pcs',
            'outstanding' => $item->outstandingAmount(),
            'stones' => [],
        ];

        if ($item->line_type === LineType::JEWELLERY_ITEM && $item->jewelleryItem) {
            $live = $this->lookupService->jewelleryPayload($item->jewelleryItem);

            // Start from what the piece was worth when it was issued: today's gold
            // rate and the stones as priced, with whatever is left of that value
            // as a fixed making charge, so the price shown is the issued price.
            $rate = (string) ($this->goldRateRepository->currentRateMap()[$live['purity_id_snapshot']] ?? '');
            $making = 0.0;
            if ($rate !== '' && (float) $item->unit_price > 0) {
                $stones = $live['stones'];
                $line = array_merge($live, ['gold_rate_applied' => $rate, 'making_charge_type' => MakingChargeType::NONE->value]);
                unset($line['stones']);
                $making = max(0.0, round((float) $item->unit_price - (float) $this->pricing->calculateLine($line, $stones)['line_total'], 2));
            }

            return array_merge($base, [
                'jewellery_item_id' => $live['jewellery_item_id'],
                'purity_id_snapshot' => $live['purity_id_snapshot'],
                'gross_weight_snapshot' => $live['gross_weight_snapshot'],
                'net_weight_snapshot' => $live['net_weight_snapshot'],
                'metal_snapshot' => $live['metal_snapshot'] ?? $item->metal_snapshot,
                'purity_snapshot' => $live['purity_snapshot'] ?? $item->purity_snapshot,
                'category_snapshot' => $live['category_snapshot'] ?? $item->category_snapshot,
                'gold_rate_applied' => $rate,
                'making_charge_type' => $making > 0 ? MakingChargeType::FIXED->value : MakingChargeType::NONE->value,
                'making_charge_value' => $making > 0 ? number_format($making, 2, '.', '') : '',
                'stones' => $live['stones'],
            ]);
        }

        return array_merge($base, [
            'stone_id' => $item->stone_id,
            // What it was issued at: per piece, or per gram on a weighed line.
            'price_per_piece' => (float) $item->unit_price > 0 ? number_format((float) $item->unit_price, 2, '.', '') : '',
            'pay_qty' => '',
        ]);
    }

    /**
     * Prefill the create payload when the user arrived from a creditor profile.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withSelectedCreditor(array $payload, ?string $uuid): array
    {
        $uuid = trim((string) $uuid);
        if ($uuid === '') {
            return $payload;
        }

        $creditor = Creditor::query()->active()->where('uuid', $uuid)->first();
        if (! $creditor) {
            return $payload;
        }

        $payload['creditor_uuid'] = $creditor->uuid;
        $payload['creditor'] = [
            'uuid' => $creditor->uuid,
            'name' => $creditor->name,
            'phone' => $creditor->phone,
            'creditor_code' => $creditor->creditor_code,
        ];

        return $payload;
    }

    /**
     * Resolve a live creditor on the actor's branch. Inactive or foreign
     * creditors are refused so a performa cannot attach a sales customer
     * or a deactivated party.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveCreditorId(array $data, User $actor): ?int
    {
        $uuid = trim((string) ($data['creditor_uuid'] ?? ''));

        if ($uuid !== '') {
            $creditor = Creditor::query()
                ->active()
                ->where('uuid', $uuid)
                ->where('branch_id', $actor->branch_id)
                ->first();

            if ($creditor && ! empty($data['update_customer'])) {
                $this->creditorService->syncFromTicket($creditor, $data, $actor);
            }

            return $creditor ? (int) $creditor->id : null;
        }

        return $this->creditorFromWalkin($data, $actor);
    }

    /**
     * Someone the POS has not seen before becomes a creditor, so performas
     * only ever hang off a registered creditor. A phone already on the
     * branch's creditors is reused rather than registered twice.
     *
     * @param  array<string, mixed>  $data
     */
    private function creditorFromWalkin(array $data, User $actor): ?int
    {
        $phone = trim((string) ($data['walkin_phone'] ?? ''));
        $name = trim((string) ($data['walkin_name'] ?? ''));

        if ($phone === '' || $name === '') {
            return null;
        }

        $existing = Creditor::query()
            ->active()
            ->where('branch_id', $actor->branch_id)
            ->where('phone', $phone)
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        Gate::forUser($actor)->authorize('create', Creditor::class);

        return (int) $this->creditorService->create([
            'name' => $name,
            'phone' => $phone,
            'email' => trim((string) ($data['walkin_email'] ?? '')) ?: null,
            'nie' => trim((string) ($data['walkin_nie'] ?? '')) ?: null,
            'address' => trim((string) ($data['walkin_address'] ?? '')) ?: null,
        ], $actor)->id;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertLinesAvailable(array $lines, User $actor): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item to the performa.']);
        }

        $jewelleryIds = [];

        foreach ($lines as $index => $line) {
            $lineNum = $index + 1;
            $type = LineType::from((string) $line['line_type']);

            if ($type === LineType::JEWELLERY_ITEM) {
                $itemId = (int) ($line['jewellery_item_id'] ?? 0);
                if (isset($jewelleryIds[$itemId])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => "{$line['item_code_snapshot']} is already on this performa.",
                    ]);
                }
                $jewelleryIds[$itemId] = true;

                $item = JewelleryItem::query()
                    ->where('branch_id', $actor->branch_id)
                    ->whereKey($itemId)
                    ->lockForUpdate()
                    ->first();

                if (! $item || $item->status !== JewelleryItemStatus::AVAILABLE) {
                    throw ValidationException::withMessages([
                        "lines.{$index}" => "Line {$lineNum}: {$line['item_code_snapshot']} is not available.",
                    ]);
                }
            } else {
                $stoneId = (int) ($line['stone_id'] ?? 0);
                $qty = max(1, (int) ($line['quantity'] ?? 1));
                $stone = Stone::query()
                    ->where('branch_id', $actor->branch_id)
                    ->whereKey($stoneId)
                    ->lockForUpdate()
                    ->first();

                // A weighed lot is checked in grams, across every line of the same lot.
                if ($stone && $stone->isWeighed()) {
                    $wanted = collect($lines)
                        ->filter(fn ($row): bool => (int) ($row['stone_id'] ?? 0) === $stoneId)
                        ->sum(fn ($row): float => (float) ($row['weight_grams'] ?? 0));
                    $available = round((float) $stone->weight_grams, 3);

                    if ($wanted <= 0 || $available + 0.0005 < $wanted) {
                        throw ValidationException::withMessages([
                            "lines.{$index}" => "Line {$lineNum}: {$line['item_code_snapshot']} has only {$available} g in stock, {$wanted} g requested.",
                        ]);
                    }

                    continue;
                }

                if (! $stone || (int) $stone->quantity < $qty) {
                    $available = $stone ? (int) $stone->quantity : 0;
                    throw ValidationException::withMessages([
                        "lines.{$index}" => "Line {$lineNum}: {$line['item_code_snapshot']} has only {$available} in stock, {$qty} requested.",
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function writeLine(Performa $performa, array $line, int $index, float $unitPrice = 0.0): PerformaItem
    {
        $type = LineType::from((string) $line['line_type']);
        $qty = $type === LineType::JEWELLERY_ITEM ? 1 : max(1, (int) ($line['quantity'] ?? 1));

        return PerformaItem::query()->create([
            'performa_id' => $performa->id,
            'line_type' => $type,
            'jewellery_item_id' => $type === LineType::JEWELLERY_ITEM ? ($line['jewellery_item_id'] ?? null) : null,
            'stone_id' => $type === LineType::LOOSE_STONE ? ($line['stone_id'] ?? null) : null,
            'item_code_snapshot' => $line['item_code_snapshot'] ?? '',
            'name_snapshot' => $line['name_snapshot'] ?? '',
            'category_snapshot' => $line['category_snapshot'] ?? null,
            'metal_snapshot' => $line['metal_snapshot'] ?? null,
            'purity_snapshot' => $line['purity_snapshot'] ?? null,
            'gross_weight_snapshot' => $line['gross_weight_snapshot'] ?? null,
            'net_weight_snapshot' => $line['net_weight_snapshot'] ?? null,
            'quantity_issued' => $qty,
            'quantity_settled' => 0,
            'quantity_returned' => 0,
            // A weighed line is counted in grams; its unit price is the price of one gram.
            'weight_issued' => (float) ($line['weight_grams'] ?? 0) > 0 ? round((float) $line['weight_grams'], 3) : 0,
            'unit_price' => $unitPrice,
            'sort_order' => $index,
        ]);
    }

    /**
     * What one piece of a line was worth at issue, through the same pricing
     * as a sale: a jewellery piece is metal at today's rate for its purity
     * plus its set stones; a loose stone is its price per piece. Zero when
     * there is nothing to price it from (no gold rate, cost not visible).
     *
     * @param  array<string, mixed>  $line  Hydrated line
     * @param  array<int|string, string>  $rates  Current gold rate per purity id
     */
    private function unitPrice(array $line, array $rates): float
    {
        if (LineType::from((string) $line['line_type']) === LineType::LOOSE_STONE) {
            return $this->pricing->money($line['price_per_piece'] ?? 0);
        }

        $stones = $line['stones'] ?? [];
        unset($line['stones']);

        // A rate typed on the POS ticket wins; otherwise today's rate for the purity.
        if ($this->pricing->money($line['gold_rate_applied'] ?? 0) <= 0) {
            $line['gold_rate_applied'] = $rates[$line['purity_id_snapshot'] ?? 0] ?? 0;
        }
        $line['making_charge_type'] = $line['making_charge_type'] ?? MakingChargeType::NONE->value;

        return (float) $this->pricing->calculateLine($line, $stones)['line_total'];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function applyIssueStock(array $line, Performa $performa, User $actor): void
    {
        $type = LineType::from((string) $line['line_type']);

        if ($type === LineType::JEWELLERY_ITEM) {
            $item = JewelleryItem::query()->lockForUpdate()->findOrFail($line['jewellery_item_id']);
            $this->jewelleryItemService->markIssuedOnCredit($item, $performa->performa_number, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($line['stone_id']);

        if ($stone->isWeighed()) {
            $this->stoneStockService->applyWeight(
                $stone,
                StoneMovementType::ISSUED_ON_CREDIT,
                -round((float) ($line['weight_grams'] ?? 0), 3),
                $actor,
                $performa,
                'Issued on performa '.$performa->performa_number
            );

            return;
        }

        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $this->stoneStockService->apply(
            $stone,
            StoneMovementType::ISSUED_ON_CREDIT,
            -$qty,
            $actor,
            $performa,
            'Issued on performa '.$performa->performa_number
        );
    }

    private function applyReturnStock(PerformaItem $item, int|float $qty, Performa $performa, User $actor): void
    {
        if ($item->line_type === LineType::JEWELLERY_ITEM) {
            if ((int) $qty !== 1 || $item->outstandingQuantity() !== 1) {
                throw ValidationException::withMessages([
                    "lines.{$item->id}" => 'A jewellery piece must be returned in full.',
                ]);
            }

            $jewellery = JewelleryItem::query()->lockForUpdate()->findOrFail($item->jewellery_item_id);
            $this->jewelleryItemService->restoreFromCredit($jewellery, $performa->performa_number, $actor);

            return;
        }

        $stone = Stone::query()->lockForUpdate()->findOrFail($item->stone_id);

        if ($item->isWeighed()) {
            $this->stoneStockService->applyWeight(
                $stone,
                StoneMovementType::RETURNED_FROM_CREDIT,
                round((float) $qty, 3),
                $actor,
                $performa,
                'Returned from performa '.$performa->performa_number
            );

            return;
        }

        $this->stoneStockService->apply(
            $stone,
            StoneMovementType::RETURNED_FROM_CREDIT,
            (int) $qty,
            $actor,
            $performa,
            'Returned from performa '.$performa->performa_number
        );
    }

    /**
     * Map of performa_item_id => qty to return. Missing keys mean "all outstanding"
     * when return_all is set.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int|float>
     */
    private function requestedReturnQty(Performa $performa, array $data): array
    {
        $map = [];

        if (! empty($data['return_all'])) {
            foreach ($performa->items as $item) {
                $outstanding = $item->outstandingAmount();
                if ($outstanding > 0) {
                    $map[$item->id] = $outstanding;
                }
            }

            return $map;
        }

        foreach ($data['lines'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['performa_item_id'] ?? 0);
            $qty = round((float) ($row['quantity'] ?? 0), 3);
            if ($id > 0 && $qty > 0) {
                $map[$id] = $qty;
            }
        }

        return $map;
    }
}
