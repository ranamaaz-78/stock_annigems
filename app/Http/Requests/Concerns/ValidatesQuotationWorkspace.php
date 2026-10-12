<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DiscountType;
use App\Enums\LineType;
use App\Enums\MakingChargeType;
use App\Enums\TaxType;
use App\Models\JewelleryItem;
use App\Models\Stone;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ValidatesQuotationWorkspace
 *
 * Shared create/update rules for the quotation workspace payload. Direct
 * invoice issue uses the same line and customer rules so a cashier cannot
 * skip validation by choosing Save invoice instead of Save quotation.
 */
trait ValidatesQuotationWorkspace
{
    /**
     * Resolve public uuids to integer PKs and drop the opposite line's id
     * so a jewellery row cannot fail the stone_id integer rule.
     *
     * The workspace JSON includes both ids and uuids (lookup payload). A
     * jewellery line often arrives with stone_id=null or a leftover uuid;
     * Laravel then reports "stone_id must be an integer".
     */
    protected function prepareWorkspaceLines(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $type = (string) ($line['line_type'] ?? '');

            if ($type === LineType::JEWELLERY_ITEM->value) {
                $lines[$index]['stone_id'] = null;
                $lines[$index]['jewellery_item_id'] = $this->numericId($line['jewellery_item_id'] ?? null)
                    ?? $this->jewelleryItemIdFromUuid($line['jewellery_item_uuid'] ?? $line['jewellery_item_id'] ?? null);
            }

            if ($type === LineType::LOOSE_STONE->value) {
                $lines[$index]['jewellery_item_id'] = null;
                $lines[$index]['stone_id'] = $this->numericId($line['stone_id'] ?? null)
                    ?? $this->stoneIdFromUuid($line['stone_uuid'] ?? $line['stone_id'] ?? null);
            }
        }

        $this->merge(['lines' => $lines]);
    }

    /**
     * Identity, customer, discount, and line pricing rules.
     *
     * Totals are recalculated server-side — submitted grand totals are not
     * in this list and must never be trusted.
     *
     * @return array<string, mixed>
     */
    protected function workspaceRules(): array
    {
        $maxRate = (float) config('annigems.gold_rates.rate_max', 999999.99);

        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_uuid' => ['nullable', 'uuid', 'exists:customers,uuid'],
            'walkin_name' => ['nullable', 'string', 'max:150', 'required_without_all:customer_id,customer_uuid'],
            // Phone is required on the walk-in path because a customer row is
            // created (or reused) from it. Unique is not enforced here — the
            // service attaches the existing customer when the number matches.
            'walkin_phone' => [
                'nullable',
                'string',
                'max:30',
                'required_without_all:customer_id,customer_uuid',
                'regex:/^[0-9+\-\s()]{7,30}$/',
            ],
            // Optional on walk-in / POS; stored only when a new customer row is created.
            'walkin_email' => ['nullable', 'email', 'max:150'],
            // POS tax toggle. Omitted on the quotation workspace so catalog GST still applies.
            'apply_tax' => ['sometimes', 'boolean'],
            'quotation_date' => ['required', 'date'],
            'default_gold_rate' => ['nullable', 'numeric', 'min:0', 'max:'.$maxRate],
            'default_stone_price' => ['nullable', 'numeric', 'min:0'],
            'default_making_charge_type' => ['nullable', Rule::enum(MakingChargeType::class)],
            'default_making_charge_value' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'tax_type' => ['required', Rule::enum(TaxType::class)],
            'tax_rate' => ['nullable', 'numeric', 'min:0'],
            // The rates SuperAdmin manages, named by uuid. The percentage is
            // looked up on the server, so tax_rate above is never trusted.
            'tax_rate_uuid' => ['nullable', 'uuid', Rule::exists('tax_rates', 'uuid')->where('kind', 'iva')->whereNull('deleted_at')],
            'recargo_rate_uuid' => ['nullable', 'uuid', Rule::exists('tax_rates', 'uuid')->where('kind', 'recargo')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_type' => ['required', Rule::enum(LineType::class)],

            // Identity is all the server needs: SalesLookupService::hydrateLines()
            // rebuilds every descriptive snapshot (code, name, purity, weights,
            // stone carats) from the live record, so those fields are neither
            // required nor trusted here.
            'lines.*.jewellery_item_id' => [
                'exclude_unless:lines.*.line_type,'.LineType::JEWELLERY_ITEM->value,
                'required',
                'integer',
            ],
            'lines.*.stone_id' => [
                'exclude_unless:lines.*.line_type,'.LineType::LOOSE_STONE->value,
                'required',
                'integer',
            ],

            // Operator-chosen pricing — the only line values that survive.
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.gold_rate_applied' => ['nullable', 'numeric', 'min:0'],
            'lines.*.making_charge_type' => ['nullable', Rule::enum(MakingChargeType::class)],
            'lines.*.making_charge_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.price_per_piece' => ['nullable', 'numeric', 'min:0'],

            'lines.*.stones' => ['nullable', 'array'],
            // Identifies which allocation a submitted price belongs to.
            'lines.*.stones.*.jewellery_item_stone_id' => ['nullable', 'integer'],
            'lines.*.stones.*.price_per_piece' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Human-readable field errors for the workspace payload.
     *
     * @return array<string, string>
     */
    protected function workspaceMessages(): array
    {
        return [
            'lines.required' => 'Add at least one item to the quotation.',
            'lines.min' => 'Add at least one item to the quotation.',
            'walkin_name.required_without_all' => 'Select a customer or enter a walk-in name and phone.',
            'walkin_phone.required_without_all' => 'Enter the walk-in customer phone.',
            'walkin_phone.regex' => 'Enter a valid phone number.',
            'walkin_email.email' => 'Enter a valid email address.',
            'quotation_date.required' => 'Quotation date is required.',
            'lines.*.jewellery_item_id.required' => 'A scanned item on this quotation is missing its reference. Remove the line and scan it again.',
            'lines.*.jewellery_item_id.integer' => 'A scanned item on this quotation is missing its reference. Remove the line and scan it again.',
            'lines.*.stone_id.required' => 'A loose stone line is missing its reference. Remove the line and scan it again.',
            'lines.*.stone_id.integer' => 'A loose stone line is missing its reference. Remove the line and scan it again.',
        ];
    }

    /**
     * Positive integer PK, or null when the value is a uuid / empty.
     */
    private function numericId(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * Look up a jewellery piece by public uuid (never trust a client-supplied id).
     */
    private function jewelleryItemIdFromUuid(mixed $uuid): ?int
    {
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            return null;
        }

        $id = JewelleryItem::query()->where('uuid', $uuid)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Look up a stone packet by public uuid.
     */
    private function stoneIdFromUuid(mixed $uuid): ?int
    {
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            return null;
        }

        $id = Stone::query()->where('uuid', $uuid)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
