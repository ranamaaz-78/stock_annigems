<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Arr;

/**
 * RejectsAttributionInput
 *
 * Attribution columns are written exclusively by TracksUserAttribution.
 * This concern strips them from Form Request validation data so they can
 * never be accepted from HTTP input, even if a child request accidentally
 * lists them in rules().
 *
 * Also strips `uuid` and `id` (PATCH 02): those are generated / internal
 * and may only be used to look a record up, never to set identity.
 *
 * Apply to every Form Request. Uses validationData() so it does not clash
 * with child prepareForValidation() methods.
 */
trait RejectsAttributionInput
{
    /**
     * Keys that must never come from the client.
     *
     * Includes the standard set plus action-specific aliases used on
     * movement / invoice / repair tables from Module 3 onward.
     *
     * @var list<string>
     */
    private const ATTRIBUTION_INPUT_KEYS = [
        'created_by',
        'created_by_role',
        'updated_by',
        'updated_by_role',
        'user_role',
        'performed_by',
        'performed_by_role',
        'received_by',
        'received_by_role',
        'voided_by',
        'voided_by_role',
        'initiated_by',
        'initiated_by_role',
        // PATCH 02: identity is generated server-side; never accepted from the client.
        'uuid',
        'id',
        // Generated identifiers / stock — never accepted from HTTP input.
        'stone_code',
        'barcode',
        'quantity',
        'intake_number',
        'attribute_fingerprint',
        'item_code',
        'qr_token',
        'stone_weight_carat',
        'stone_weight_grams',
        'net_weight',
    ];

    /**
     * Drop attribution fields before rules run.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return Arr::except(parent::validationData(), self::ATTRIBUTION_INPUT_KEYS);
    }
}
