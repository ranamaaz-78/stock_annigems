<?php

namespace App\Http\Requests\Branch;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RecordPaymentRequest
 *
 * The method and reference for the payment of a pending invoice or delivery
 * note. The amount is the balance still due; it is not taken from the browser.
 */
class RecordPaymentRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Whoever may record payment on the document in the address.
     */
    public function authorize(): bool
    {
        $document = $this->route('invoice') ?? $this->route('deliveryNote');

        return ($document instanceof Invoice || $document instanceof DeliveryNote)
            && ($this->user()?->can('recordPayment', $document) ?? false);
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
        return ['payment_method.required' => __('Select a payment method.')];
    }
}
