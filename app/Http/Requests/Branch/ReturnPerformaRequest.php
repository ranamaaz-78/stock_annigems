<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\Performa;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ReturnPerformaRequest
 *
 * Return unpaid goods to stock. return_all takes every outstanding qty;
 * otherwise each line names a performa_item_id and quantity.
 */
class ReturnPerformaRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Return unpaid goods on an open performa.
     */
    public function authorize(): bool
    {
        $performa = $this->route('performa');

        return $performa instanceof Performa
            && ($this->user()?->can('returnUnpaid', $performa) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'return_all' => ['nullable', 'boolean'],
            'lines' => ['required_without:return_all', 'array'],
            'lines.*.performa_item_id' => ['required_with:lines', 'integer', 'exists:performa_items,id'],
            // Pieces, or grams on a weighed stone line (the service insists on whole pieces otherwise).
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0', 'decimal:0,3'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required_without' => 'Select at least one unpaid line to return.',
            'lines.*.quantity.gt' => 'Enter an amount above zero.',
        ];
    }
}
