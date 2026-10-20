<?php

namespace App\Services;

use App\Enums\LabelKind;
use App\Enums\LabelSize;
use App\Models\JewelleryItem;
use Illuminate\Support\Collection;

/**
 * JewelleryLabelService
 *
 * Each piece is unique, so a code prints once. Selected pieces share one
 * A4 grid. Barcode, QR, and both are separate jobs for the same items.
 */
class JewelleryLabelService
{
    public function __construct(
        private BarcodeService $barcodeService,
        private JewelleryQrService $qrService,
    ) {}

    /**
     * Data URIs keyed by item uuid (used by the item show screen).
     *
     * @param  Collection<int, JewelleryItem>  $items
     * @return array{barcodes: array<string, string>, qrs: array<string, string>}
     */
    public function urisFor(Collection $items): array
    {
        $barcodes = [];
        $qrs = [];

        foreach ($items as $item) {
            $barcodes[$item->uuid] = $item->barcode
                ? $this->barcodeService->dataUri((string) $item->barcode, 2, 40)
                : '';
            $qrs[$item->uuid] = $item->qr_token
                ? $this->qrService->dataUri($item->qr_token, 180)
                : '';
        }

        return ['barcodes' => $barcodes, 'qrs' => $qrs];
    }

    /**
     * Items in the order the operator selected.
     *
     * @param  list<string>  $uuids
     * @return Collection<int, JewelleryItem>
     */
    public function itemsForUuids(array $uuids): Collection
    {
        $items = JewelleryItem::query()
            ->with(['branch', 'metalType', 'purity'])
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');

        return collect($uuids)
            ->map(fn (string $uuid) => $items->get($uuid))
            ->filter()
            ->values();
    }

    /**
     * What the print dialog needs to draw each piece's label: name, item
     * code, six digit barcode number and a vector barcode. Pieces without a
     * barcode (or QR, for QR labels) are counted, not listed. Jewellery carries no price.
     *
     * @param  Collection<int, JewelleryItem>  $items
     * @return array{stones: list<array<string, string>>, skipped: int}
     */
    public function dialogData(Collection $items, LabelKind $kind = LabelKind::BARCODE): array
    {
        $labelled = $items->filter(fn (JewelleryItem $item): bool => $this->itemPrintable($item, $kind))->values();

        return [
            // Same keys as stones, so one dialog prints both.
            'stones' => $labelled->map(fn (JewelleryItem $item): array => [
                'uuid' => (string) $item->uuid,
                'name' => (string) $item->name,
                'stone_code' => (string) $item->item_code,
                'barcode' => (string) $item->barcode,
                'price' => '',
                'barcode_svg' => filled($item->barcode) ? $this->barcodeService->svg((string) $item->barcode) : '',
                // Public product page QR, large enough to stay sharp on any label.
                'qr_uri' => filled($item->qr_token) ? $this->qrService->dataUri($item->qr_token, 400) : '',
            ])->all(),
            'skipped' => $items->count() - $labelled->count(),
        ];
    }

    /**
     * One cell per piece on a shared sheet.
     *
     * @param  Collection<int, JewelleryItem>  $items
     * @return array<string, mixed>
     */
    public function printView(Collection $items, LabelKind $kind): array
    {
        $printable = $items->filter(fn (JewelleryItem $item): bool => $this->itemPrintable($item, $kind))->values();

        $branchId = $printable->first()?->branch_id;
        $size = LabelSize::tryFrom((string) branch_setting('label_size', $branchId, LabelSize::MEDIUM->value))
            ?? LabelSize::MEDIUM;
        $first = $printable->first();

        $sheets = [];
        foreach ($printable as $item) {
            $sheets[] = [
                'name' => $item->name,
                'code' => (string) $item->item_code,
                'barcodeUri' => ($kind !== LabelKind::QR && filled($item->barcode))
                    ? $this->barcodeService->dataUri((string) $item->barcode, 2, 40)
                    : '',
                'qrUri' => ($kind !== LabelKind::BARCODE && filled($item->qr_token))
                    ? $this->qrService->dataUri($item->qr_token, 180)
                    : '',
            ];
        }

        return [
            'sheets' => $sheets,
            'copies' => 1,
            'columns' => $this->columnsFor($size, $kind),
            'labelSize' => $size->value,
            'kind' => $kind->value,
            'layout' => 'unique',
            'uuids' => $printable->pluck('uuid')->all(),
            'documentTitle' => $kind->label().' labels',
            'applyUrl' => $printable->count() > 1
                ? route('branch.jewellery-items.labels.bulk', $kind)
                : ($first ? route('branch.jewellery-items.label', [$first, $kind]) : url()->current()),
        ];
    }

    /**
     * Whether this piece has the token the chosen job needs.
     */
    private function itemPrintable(JewelleryItem $item, LabelKind $kind): bool
    {
        return match ($kind) {
            LabelKind::BARCODE => filled($item->barcode),
            LabelKind::QR => filled($item->qr_token),
            LabelKind::BOTH => filled($item->barcode) || filled($item->qr_token),
        };
    }

    /**
     * Combined barcode+QR cells are wider, so fewer columns fit on A4.
     */
    private function columnsFor(LabelSize $size, LabelKind $kind): int
    {
        if ($kind !== LabelKind::BOTH) {
            return $size->sheetColumns();
        }

        return match ($size) {
            LabelSize::SMALL => 3,
            LabelSize::MEDIUM => 2,
            LabelSize::LARGE => 1,
        };
    }
}
