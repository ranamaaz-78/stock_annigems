<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use App\Models\JewelleryItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LookupJewelleryStoneRequest
 *
 * Barcode lookup while allocating stones on the item form. Authorized by
 * jewellery create/edit, not stones.view.
 */
class LookupJewelleryStoneRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Actor is building a piece.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (
            $user->can('create', JewelleryItem::class)
            || $user->hasPermission('jewellery.edit')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => __('Scan or type a barcode.'),
        ];
    }
}
