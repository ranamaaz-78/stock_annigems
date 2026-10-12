<?php

namespace App\Http\Requests\Branch;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ConvertQuotationRequest
 *
 * Validates payment details when converting a quotation to an invoice.
 */
class ConvertQuotationRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * User must be allowed to convert this quotation.
     */
    public function authorize(): bool
    {
        $quotation = $this->route('quotation');

        return $quotation instanceof Quotation
            && ($this->user()?->can('convert', $quotation) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Select a payment method.',
        ];
    }
}
