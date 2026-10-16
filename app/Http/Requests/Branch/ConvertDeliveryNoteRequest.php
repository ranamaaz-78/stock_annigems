<?php

namespace App\Http\Requests\Branch;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\DeliveryNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ConvertDeliveryNoteRequest
 *
 * Payment details taken when a delivery note becomes an invoice.
 */
class ConvertDeliveryNoteRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * User must be allowed to invoice this note.
     */
    public function authorize(): bool
    {
        $note = $this->route('deliveryNote');

        return $note instanceof DeliveryNote
            && ($this->user()?->can('convert', $note) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            // Blank IVA = no IVA on this invoice; recargo is optional.
            'tax_rate_uuid' => ['nullable', 'uuid', Rule::exists('tax_rates', 'uuid')->where('kind', 'iva')->whereNull('deleted_at')],
            'recargo_rate_uuid' => ['nullable', 'uuid', Rule::exists('tax_rates', 'uuid')->where('kind', 'recargo')->whereNull('deleted_at')],
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