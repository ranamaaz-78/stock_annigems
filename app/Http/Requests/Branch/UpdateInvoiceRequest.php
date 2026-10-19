<?php

namespace App\Http\Requests\Branch;

use App\Models\Invoice;

/**
 * UpdateInvoiceRequest
 *
 * The workspace payload again, sent against an invoice that has no payment
 * yet. Payment fields are not accepted: editing never records money.
 */
class UpdateInvoiceRequest extends StoreDirectInvoiceRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice
            && ($this->user()?->can('update', $invoice) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->workspaceRules();
    }
}
