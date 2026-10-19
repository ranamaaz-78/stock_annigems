<?php

namespace App\Http\Requests\Branch;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\ValidatesQuotationWorkspace;
use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreDirectInvoiceRequest
 *
 * Validates a quotation-workspace payload plus payment details when the
 * operator issues a completed invoice without first saving a quotation.
 *
 * Line, customer, and discount rules match StoreQuotationRequest so the
 * two buttons on the same screen cannot take different validation paths.
 */
class StoreDirectInvoiceRequest extends FormRequest
{
    use RejectsAttributionInput;
    use ValidatesQuotationWorkspace;

    /**
     * Direct invoice requires both workspace access and convert permission.
     * There is no quotation yet, so QuotationPolicy::convert cannot run.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('create', Quotation::class)
            && $user->hasPermission('quotations.convert');
    }

    /**
     * Same uuid → PK resolution as quotation save.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareWorkspaceLines();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->workspaceRules(), [
            // Pending until the payment is recorded; "paid" needs the method.
            'payment_status' => ['nullable', Rule::enum(\App\Enums\PaymentStatus::class)],
            'payment_method' => ['required_unless:payment_status,pending', 'nullable', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->workspaceMessages(), [
            'lines.required' => 'Add at least one item to the invoice.',
            'lines.min' => 'Add at least one item to the invoice.',
            'payment_method.required' => 'Select a payment method.',
        ]);
    }
}
