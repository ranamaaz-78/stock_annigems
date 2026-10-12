<?php

namespace App\Http\Requests\Branch;

use App\Enums\JewelleryItemStatus;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use App\Models\JewelleryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateJewelleryItemStatusRequest
 *
 * Manual status change. sold / transferred are rejected.
 */
class UpdateJewelleryItemStatusRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Actor may change status on this piece.
     */
    public function authorize(): bool
    {
        $item = $this->route('jewelleryItem');

        return $item instanceof JewelleryItem
            && ($this->user()?->can('changeStatus', $item) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(JewelleryItemStatus::usableValues())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'That status cannot be set from jewellery management.',
        ];
    }
}
