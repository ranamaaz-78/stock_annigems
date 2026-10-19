<?php

namespace App\Services;

use App\Enums\LineType;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JewelleryItem;
use App\Models\Stone;
use Illuminate\Support\Collection;

/**
 * PosDocumentLoader
 *
 * Turns a saved invoice or delivery note into what the POS needs to open it
 * for editing: the header, the customer, the discount and tax choices, and
 * every line as a POS line with the prices it was made with. Stock the
 * document is holding counts as available, since editing puts it back first.
 */
class PosDocumentLoader
{
    public function __construct(
        private SalesLookupService $lookup,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['items.itemStones', 'customer']);

        return [
            'type' => 'invoice',
            'uuid' => $invoice->uuid,
            'number' => $invoice->invoice_number,
            'date' => $invoice->invoice_date?->toDateString(),
            'notes' => (string) $invoice->notes,
            'customer' => $this->customer($invoice->customer),
            'discount' => [
                'type' => $invoice->discount_type->value,
                'value' => (string) $invoice->discount_value,
                'on' => (float) $invoice->discount_amount > 0,
            ],
            'tax' => $this->tax($invoice->tax_name, $invoice->recargo_name),
            'lines' => $this->lines($invoice->items),
            'update_url' => route('branch.invoices.update', $invoice),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deliveryNote(DeliveryNote $note): array
    {
        $note->loadMissing(['items.itemStones', 'customer']);

        return [
            'type' => 'delivery_note',
            'uuid' => $note->uuid,
            'number' => $note->delivery_note_number,
            'date' => $note->delivery_date?->toDateString(),
            'notes' => (string) $note->notes,
            'customer' => $this->customer($note->customer),
            'discount' => [
                'type' => $note->discount_type->value,
                'value' => (string) $note->discount_value,
                'on' => (float) $note->discount_amount > 0,
            ],
            'tax' => ['apply' => false, 'iva_uuid' => null, 'recargo_uuid' => null],
            'lines' => $this->lines($note->items),
            'update_url' => route('branch.delivery-notes.update', $note),
        ];
    }

    /**
     * IVA and recargo as the rates the document named, found again in the
     * SuperAdmin list by name. A rate that has since been removed falls back
     * to none rather than guessing.
     *
     * @return array{apply: bool, iva_uuid: ?string, recargo_uuid: ?string}
     */
    private function tax(?string $ivaName, ?string $recargoName): array
    {
        $taxRates = app(TaxRateService::class);

        $iva = filled($ivaName)
            ? $taxRates->active(\App\Models\TaxRate::KIND_IVA)->first(fn ($r): bool => $r->name === $ivaName)
            : null;
        $rec = filled($recargoName)
            ? $taxRates->active(\App\Models\TaxRate::KIND_RECARGO)->first(fn ($r): bool => $r->name === $recargoName)
            : null;

        return ['apply' => $iva !== null, 'iva_uuid' => $iva?->uuid, 'recargo_uuid' => $rec?->uuid];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customer(?\App\Models\Customer $customer): ?array
    {
        return $customer ? [
            'uuid' => $customer->uuid,
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'nie' => $customer->nie,
            'address' => $customer->address,
        ] : null;
    }

    /**
     * @param  Collection<int, InvoiceItem|DeliveryNoteItem>  $items
     * @return list<array<string, mixed>>
     */
    private function lines(Collection $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            if ($item->line_type === LineType::JEWELLERY_ITEM) {
                $piece = JewelleryItem::query()->withoutGlobalScopes()
                    ->with(['category', 'metalType', 'purity', 'itemStones'])
                    ->find($item->jewellery_item_id);
                if (! $piece) {
                    continue;
                }

                $payload = $this->lookup->jewelleryPayload($piece);
                // The prices the document was made with, not today's.
                $prices = $item->itemStones->pluck('price_per_piece', 'jewellery_item_stone_id');
                $payload['stones'] = array_map(function (array $stone) use ($prices): array {
                    $id = $stone['jewellery_item_stone_id'] ?? null;
                    if ($id !== null && $prices->has($id)) {
                        $stone['price_per_piece'] = number_format((float) $prices[$id], 2, '.', '');
                    }

                    return $stone;
                }, $payload['stones']);

                $lines[] = array_merge($payload, [
                    'gold_rate_applied' => (string) $item->gold_rate_applied,
                    'making_charge_type' => $item->making_charge_type->value,
                    'making_charge_value' => (string) $item->making_charge_value,
                ]);

                continue;
            }

            $stone = Stone::query()->withoutGlobalScopes()->find($item->stone_id);
            if (! $stone) {
                continue;
            }

            $payload = $this->lookup->stonePayload($stone);
            $weighed = $item->weight_grams !== null && (float) $item->weight_grams > 0;
            $unit = $weighed
                ? ((float) $item->stone_value / max(0.001, (float) $item->weight_grams))
                : ((float) $item->stone_value / max(1, (int) $item->quantity));

            $lines[] = array_merge($payload, [
                // The goods it holds are back on the shelf while it is edited.
                'available_quantity' => (int) $stone->quantity + (int) $item->quantity,
                'available_grams' => $weighed
                    ? number_format((float) $stone->weight_grams + (float) $item->weight_grams, 3, '.', '')
                    : null,
                'quantity' => $weighed ? 1 : (int) $item->quantity,
                'weight_grams' => $weighed ? number_format((float) $item->weight_grams, 3, '.', '') : null,
                'price_per_piece' => number_format($unit, 2, '.', ''),
            ]);
        }

        return $lines;
    }
}
