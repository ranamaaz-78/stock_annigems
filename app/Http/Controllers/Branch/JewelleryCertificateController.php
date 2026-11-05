<?php

namespace App\Http\Controllers\Branch;

use App\Enums\StonePricingMode;
use App\Http\Controllers\Controller;
use App\Models\JewelleryItem;
use App\Services\JewelleryQrService;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * JewelleryCertificateController
 *
 * A 5 x 4.5 inch landscape Certificate of Authenticity for one piece. It is a
 * print page: the browser's print dialog prints it or saves it as a PDF, so one
 * button does both. No cost, rate or internal note ever appears on it.
 */
class JewelleryCertificateController extends Controller
{
    /**
     * Stone rows shown before the rest are folded into one "other stones" line.
     */
    private const MAX_STONE_ROWS = 4;

    public function __construct(private JewelleryQrService $qrService) {}

    /**
     * The certificate for one piece in the actor's branch.
     */
    public function show(JewelleryItem $jewelleryItem): View
    {
        $this->authorize('view', $jewelleryItem);
        $jewelleryItem->load(['branch', 'category', 'metalType', 'purity', 'itemStones', 'images']);

        $photo = $jewelleryItem->images->first();

        return view('branch.jewellery-items.certificate', [
            'item' => $jewelleryItem,
            'stoneRows' => $this->stoneRows($jewelleryItem),
            // Leads to the piece's public verification page; none when the piece has no QR token.
            'qrUri' => filled($jewelleryItem->qr_token) ? $this->qrService->dataUri($jewelleryItem->qr_token, 240) : '',
            'photoUrl' => $photo ? route('branch.jewellery-items.images.show', [$jewelleryItem, $photo]) : null,
        ]);
    }

    /**
     * One line per kind of stone: its name and what it weighs ("0.41 cts").
     *
     * Stones with the same name are added together. A piece-counted stone with
     * no carat shows its count instead.
     *
     * @return Collection<int, array{label: string, value: string}>
     */
    private function stoneRows(JewelleryItem $item): Collection
    {
        $rows = $item->itemStones
            ->groupBy(fn ($row): string => mb_strtoupper(trim((string) $row->stone_name_snapshot)))
            ->map(function (Collection $group, string $name): array {
                $carat = 0.0;
                $grams = 0.0;
                $pieces = 0;
                foreach ($group as $row) {
                    if ($row->weight_grams !== null && $row->pricing_mode === StonePricingMode::PER_GRAM) {
                        $grams += (float) $row->weight_grams;
                    } elseif ($row->weight_grams !== null) {
                        $carat += (float) $row->weight_grams;
                    } else {
                        $carat += (float) $row->carat_snapshot * (int) $row->quantity;
                        $pieces += (int) $row->quantity;
                    }
                }

                $parts = [];
                if ($carat > 0) {
                    $parts[] = $this->trim($carat).' cts';
                }
                if ($grams > 0) {
                    $parts[] = $this->trim($grams).' g';
                }
                if ($parts === [] && $pieces > 0) {
                    $parts[] = $pieces.' '.__('pcs');
                }

                return ['label' => $name.' '.__('WEIGHT'), 'value' => implode(' + ', $parts)];
            })
            ->values();

        if ($rows->count() <= self::MAX_STONE_ROWS) {
            return $rows;
        }

        $shown = $rows->take(self::MAX_STONE_ROWS - 1);

        return $shown->push(['label' => __('OTHER STONES'), 'value' => '+ '.($rows->count() - $shown->count())]);
    }

    /**
     * 0.410 -> "0.41", 5 -> "5".
     */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
