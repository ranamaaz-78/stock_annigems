<?php

namespace App\Http\Requests\Branch;

use App\Models\JewelleryItem;

/**
 * UpdateJewelleryItemRequest
 *
 * Same field rules as create; authorize update on the bound item.
 */
class UpdateJewelleryItemRequest extends StoreJewelleryItemRequest
{
    /**
     * Actor may edit this piece.
     */
    public function authorize(): bool
    {
        $item = $this->route('jewelleryItem');

        return $item instanceof JewelleryItem
            && ($this->user()?->can('update', $item) ?? false);
    }
}
