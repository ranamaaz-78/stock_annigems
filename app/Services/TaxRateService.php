<?php

namespace App\Services;

use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TaxRateService
 *
 * The lists SuperAdmin manages (IVA, Recargo de equivalencia) and the lookup a
 * document uses to turn a chosen rate into the figures it stores. The browser
 * only ever names a rate by uuid; the percentage always comes from here.
 */
class TaxRateService
{
    /**
     * Every rate of a kind, active or not (the management screen).
     *
     * @return Collection<int, TaxRate>
     */
    public function all(string $kind): Collection
    {
        return TaxRate::query()->ofKind($kind)->get();
    }

    /**
     * Rates a cashier may choose from.
     *
     * @return Collection<int, TaxRate>
     */
    public function active(string $kind): Collection
    {
        return TaxRate::query()->ofKind($kind)->active()->get();
    }

    /**
     * The IVA rate preselected on new documents, if one is set and active.
     */
    public function defaultIva(): ?TaxRate
    {
        return TaxRate::query()
            ->where('kind', TaxRate::KIND_IVA)
            ->where('is_default', true)
            ->active()
            ->first();
    }

    /**
     * An active rate of this kind by its public uuid.
     */
    public function findActive(string $kind, ?string $uuid): ?TaxRate
    {
        if ($uuid === null || trim($uuid) === '') {
            return null;
        }

        return TaxRate::query()->where('kind', $kind)->where('uuid', $uuid)->active()->first();
    }

    /**
     * Add a rate. The first rate of a kind becomes its default.
     *
     * @param  array{kind: string, name: string, rate: float|string, is_exempt?: bool}  $data
     */
    public function create(array $data): TaxRate
    {
        return DB::transaction(function () use ($data): TaxRate {
            $exempt = $data['kind'] === TaxRate::KIND_IVA && ! empty($data['is_exempt']);

            $rate = TaxRate::query()->create([
                'kind' => $data['kind'],
                'name' => trim($data['name']),
                'rate' => $exempt ? 0 : $data['rate'],
                'is_exempt' => $exempt,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => (int) TaxRate::query()->where('kind', $data['kind'])->max('sort_order') + 1,
            ]);

            if ($rate->kind === TaxRate::KIND_IVA && ! TaxRate::query()->where('kind', TaxRate::KIND_IVA)->where('is_default', true)->whereKeyNot($rate->id)->exists()) {
                $rate->update(['is_default' => true]);
            }

            return $rate;
        });
    }

    /**
     * Rename or re-rate. Documents already made keep their own snapshot.
     *
     * @param  array{name: string, rate: float|string, is_exempt?: bool}  $data
     */
    public function update(TaxRate $rate, array $data): TaxRate
    {
        $exempt = $rate->kind === TaxRate::KIND_IVA && ! empty($data['is_exempt']);

        $rate->update([
            'name' => trim($data['name']),
            'rate' => $exempt ? 0 : $data['rate'],
            'is_exempt' => $exempt,
        ]);

        return $rate;
    }

    /**
     * Make an IVA rate the preselected one. Only one default exists.
     */
    public function setDefault(TaxRate $rate): TaxRate
    {
        if ($rate->kind !== TaxRate::KIND_IVA) {
            throw ValidationException::withMessages(['rate' => 'Only an IVA rate can be the default.']);
        }

        if (! $rate->is_active) {
            throw ValidationException::withMessages(['rate' => 'Activate this rate before making it the default.']);
        }

        DB::transaction(function () use ($rate): void {
            TaxRate::query()->where('kind', TaxRate::KIND_IVA)->update(['is_default' => false]);
            $rate->update(['is_default' => true]);
        });

        return $rate->refresh();
    }

    /**
     * Turn a rate on or off. The default cannot be turned off.
     */
    public function toggle(TaxRate $rate): TaxRate
    {
        if ($rate->is_active && $rate->is_default) {
            throw ValidationException::withMessages([
                'rate' => 'Choose another default IVA rate before turning this one off.',
            ]);
        }

        $rate->update(['is_active' => ! $rate->is_active]);

        return $rate;
    }

    /**
     * Remove a rate from the lists. The default cannot be removed; documents
     * keep their snapshot, so nothing already issued changes.
     */
    public function delete(TaxRate $rate): void
    {
        if ($rate->is_default) {
            throw ValidationException::withMessages([
                'rate' => 'Choose another default IVA rate before deleting this one.',
            ]);
        }

        $rate->delete();
    }

    /**
     * Resolve the choice a document makes into the figures it stores.
     *
     * No IVA named means the default one (the quotation workspace behaviour)
     * unless `$withoutIva` says the ticket deliberately has none (the POS can
     * add a recargo on its own); no default means no tax. A recargo is only
     * used when named explicitly.
     *
     * @return array{tax_type: string, tax_rate: float, tax_name: ?string, recargo_rate: float, recargo_name: ?string}
     */
    public function resolve(?string $ivaUuid, ?string $recargoUuid, bool $withoutIva = false): array
    {
        if ($withoutIva) {
            $iva = null;
        } else {
            $iva = $ivaUuid !== null && trim($ivaUuid) !== ''
                ? $this->findActive(TaxRate::KIND_IVA, $ivaUuid)
                : $this->defaultIva();
        }
        $recargo = $this->findActive(TaxRate::KIND_RECARGO, $recargoUuid);

        return [
            // An exempt rate is still a percentage (0%), so the document can name it.
            'tax_type' => $iva ? 'percentage' : 'none',
            'tax_rate' => $iva ? (float) $iva->rate : 0.0,
            'tax_name' => $iva?->name,
            'recargo_rate' => $recargo ? (float) $recargo->rate : 0.0,
            'recargo_name' => $recargo?->name,
        ];
    }
}
