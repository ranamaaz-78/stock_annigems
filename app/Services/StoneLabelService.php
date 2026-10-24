<?php

namespace App\Services;

use App\Enums\LabelSize;
use App\Models\Stone;
use Illuminate\Support\Collection;

/**
 * StoneLabelService
 *
 * Packet barcode sheets. Each stone prints as one A4 page: the name at the
 * top, then a grid of the same barcode (bars + number only) so staff can
 * cut or stick multiple identical packet labels from a single print.
 */
class StoneLabelService
{
    public function __construct(private BarcodeService $barcodeService) {}

    /**
     * Data URI for one stone's barcode, or empty when none was issued.
     *
     * Draws the stored barcode value, never stone_code as a fallback —
     * otherwise disabling stone_barcode_enabled would still print a code.
     */
    public function barcodeDataUri(Stone $stone): string
    {
        if (! $stone->hasPacketBarcode()) {
            return '';
        }

        return $this->barcodeService->dataUri((string) $stone->barcode, 2, 40);
    }

    /**
     * Stones selected for a bulk sheet, preserving request order.
     *
     * @param  list<string>  $uuids
     * @return Collection<int, Stone>
     */
    public function stonesForUuids(array $uuids): Collection
    {
        $stones = Stone::query()
            ->with(['branch'])
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');

        return collect($uuids)
            ->map(fn (string $uuid) => $stones->get($uuid))
            ->filter()
            ->values();
    }

    /**
     * What the print dialog needs to draw each label itself.
     *
     * Stones without a packet barcode cannot be labelled and are counted
     * rather than listed. The barcode is returned as SVG so the dialog can
     * size it to any label without blurring.
     *
     * @param  Collection<int, Stone>  $stones
     * @return array{stones: list<array<string, string>>, skipped: int}
     */
    public function dialogData(Collection $stones): array
    {
        $labelled = $stones->filter(fn (Stone $stone): bool => $stone->hasPacketBarcode())->values();

        return [
            'stones' => $labelled->map(fn (Stone $stone): array => [
                'uuid' => (string) $stone->uuid,
                'name' => (string) $stone->name,
                'stone_code' => (string) $stone->stone_code,
                'barcode' => (string) $stone->barcode,
                'tray_no' => (string) $stone->tray_no,
                // The lot's rate with its unit, e.g. "Rs 2.50 / g".
                'price' => $stone->rateLabel(),
                'barcode_svg' => $this->barcodeService->svg((string) $stone->barcode),
            ])->all(),
            'skipped' => $stones->count() - $labelled->count(),
        ];
    }

    /**
     * Print view payload: one sheet per stone, same barcode repeated to fill
     * the page (or $requestedCopies when the operator overrides).
     *
     * @param  Collection<int, Stone>  $stones
     * @return array{
     *     sheets: list<array<string, mixed>>,
     *     copies: int,
     *     columns: int,
     *     labelSize: string,
     *     uuids: list<string>
     * }
     */
    public function printView(Collection $stones, int $requestedCopies = 0): array
    {
        $branchId = $stones->first()?->branch_id;
        $size = LabelSize::tryFrom((string) branch_setting('label_size', $branchId, LabelSize::MEDIUM->value))
            ?? LabelSize::MEDIUM;

        $copies = $size->copyCount($branchId, $requestedCopies);

        $sheets = [];
        foreach ($stones as $stone) {
            if (! $stone instanceof Stone || ! $stone->hasPacketBarcode()) {
                continue;
            }

            $barcodeUri = $this->barcodeDataUri($stone);
            $sheets[] = [
                'name' => $stone->name,
                'code' => (string) $stone->barcode,
                'barcodeUri' => $barcodeUri,
                'imageUri' => $barcodeUri,
            ];
        }

        return [
            'sheets' => $sheets,
            'copies' => $copies,
            'columns' => $size->sheetColumns(),
            'labelSize' => $size->value,
            'kind' => 'barcode',
            'uuids' => $stones->pluck('uuid')->all(),
            'documentTitle' => 'Stone labels',
            'layout' => 'repeat',
            'applyUrl' => $stones->count() > 1
                ? route('branch.stones.labels.bulk')
                : route('branch.stones.label', $stones->first()),
        ];
    }
}
