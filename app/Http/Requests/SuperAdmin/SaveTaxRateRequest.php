<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\TaxRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SaveTaxRateRequest
 *
 * Add or edit an IVA or Recargo rate. An exempt IVA rate carries no
 * percentage; every other rate is between 0 and 100 with up to three decimals.
 */
class SaveTaxRateRequest extends FormRequest
{
    /**
     * SuperAdmin only; the route group already enforces it.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('taxRate') === null;

        return [
            'kind' => $creating
                ? ['required', Rule::in([TaxRate::KIND_IVA, TaxRate::KIND_RECARGO])]
                : ['prohibited'],
            'name' => ['required', 'string', 'max:60'],
            'is_exempt' => ['sometimes', 'boolean'],
            'rate' => [
                Rule::requiredIf(! $this->boolean('is_exempt')),
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                'decimal:0,3',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Give the rate a name, e.g. IVA 21%.'),
            'rate.required' => __('Enter the percentage.'),
            'rate.max' => __('A rate cannot be more than 100%.'),
            'rate.decimal' => __('Use at most three decimal places.'),
        ];
    }
}