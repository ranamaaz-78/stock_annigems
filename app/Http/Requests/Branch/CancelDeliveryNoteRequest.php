<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\DeliveryNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CancelDeliveryNoteRequest
 *
 * Mandatory reason when a delivery note is cancelled and its goods return.
 */
class CancelDeliveryNoteRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * User must be allowed to cancel this note.
     */
    public function authorize(): bool
    {
        $note = $this->route('deliveryNote');

        return $note instanceof DeliveryNote
            && ($this->user()?->can('cancel', $note) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancel_reason.required' => __('Please give a reason for cancelling this delivery note.'),
            'cancel_reason.min' => __('The reason must be at least 10 characters.'),
        ];
    }
}