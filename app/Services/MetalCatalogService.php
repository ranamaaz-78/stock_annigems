<?php

namespace App\Services;

use App\Models\JewelleryItem;
use App\Models\MetalType;
use App\Models\Purity;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * MetalCatalogService
 *
 * Global metal types and purities. Purities are bound to a metal type.
 * In-use values cannot be deleted.
 */
class MetalCatalogService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * @return Collection<int, MetalType>
     */
    public function listMetals(): Collection
    {
        return MetalType::query()->withCount('jewelleryItems')->ordered()->get();
    }

    /**
     * @return Collection<int, Purity>
     */
    public function listPurities(): Collection
    {
        return Purity::query()->with(['metalType'])->withCount('jewelleryItems')->ordered()->get();
    }

    /**
     * Active purities for a metal (item form).
     *
     * @return Collection<int, Purity>
     */
    public function puritiesForMetal(int $metalTypeId): Collection
    {
        return Purity::query()
            ->active()
            ->where('metal_type_id', $metalTypeId)
            ->ordered()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMetal(array $data, User $actor): MetalType
    {
        $row = MetalType::query()->create($this->metalPayload($data));
        $this->auditLogService->log('metal.created', $row, null, $row->toArray(), $actor);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMetal(MetalType $metal, array $data, User $actor): MetalType
    {
        $old = $metal->toArray();
        $metal->fill($this->metalPayload($data));
        $metal->save();
        $this->auditLogService->log('metal.updated', $metal, $old, $metal->toArray(), $actor);

        return $metal->refresh();
    }

    /**
     * Soft-delete a metal when unused.
     */
    public function deleteMetal(MetalType $metal, User $actor): void
    {
        $count = JewelleryItem::query()->withoutGlobalScopes()->where('metal_type_id', $metal->id)->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'name' => "{$metal->name} is used by {$count} jewellery item(s) and cannot be deleted. Deactivate it instead.",
            ]);
        }

        if ($metal->purities()->exists()) {
            throw ValidationException::withMessages([
                'name' => "{$metal->name} still has purities. Remove those first, or deactivate the metal.",
            ]);
        }

        $old = $metal->toArray();
        $metal->delete();
        $this->auditLogService->log('metal.deleted', $metal, $old, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPurity(array $data, User $actor): Purity
    {
        $row = Purity::query()->create($this->purityPayload($data));
        $this->auditLogService->log('purity.created', $row, null, $row->toArray(), $actor);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePurity(Purity $purity, array $data, User $actor): Purity
    {
        $old = $purity->toArray();
        $purity->fill($this->purityPayload($data));
        $purity->save();
        $this->auditLogService->log('purity.updated', $purity, $old, $purity->toArray(), $actor);

        return $purity->refresh();
    }

    /**
     * Soft-delete a purity when unused.
     */
    public function deletePurity(Purity $purity, User $actor): void
    {
        $count = JewelleryItem::query()->withoutGlobalScopes()->where('purity_id', $purity->id)->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'name' => "{$purity->name} is used by {$count} jewellery item(s) and cannot be deleted. Deactivate it instead.",
            ]);
        }

        $old = $purity->toArray();
        $purity->delete();
        $this->auditLogService->log('purity.deleted', $purity, $old, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function metalPayload(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function purityPayload(array $data): array
    {
        return [
            'metal_type_id' => (int) $data['metal_type_id'],
            'name' => trim((string) $data['name']),
            'value' => $data['value'],
            'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
