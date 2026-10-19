<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * VoidInvoiceRequest
 *
 * Mandatory reason when voiding an invoice.
 */
class VoidInvoiceRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * User must have invoices.void permission for this invoice.
     */
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice
            && ($this->user()?->can('void', $invoice) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'void_reason.required' => __('Please provide a reason for voiding this invoice.'),
            'void_reason.min' => __('The void reason must be at least 10 characters.'),
        ];
    }
}
