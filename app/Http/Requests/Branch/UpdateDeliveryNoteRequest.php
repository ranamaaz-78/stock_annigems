<?php

namespace App\Http\Requests\Branch;

use App\Models\DeliveryNote;

/**
 * UpdateDeliveryNoteRequest
 *
 * The workspace payload again, sent against a delivery note that has no
 * payment yet.
 */
class UpdateDeliveryNoteRequest extends StoreDeliveryNoteRequest
{
    public function authorize(): bool
    {
        $note = $this->route('deliveryNote');

        return $note instanceof DeliveryNote
            && ($this->user()?->can('update', $note) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->workspaceRules();
    }
}
