<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\ValidatesQuotationWorkspace;
use App\Models\DeliveryNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreDeliveryNoteRequest
 *
 * A POS workspace payload that issues a delivery note. Line, customer and
 * discount rules are the same as for a quotation or direct invoice, so the
 * three buttons on the POS cannot take different validation paths. There is
 * no payment: a delivery note takes none.
 */
class StoreDeliveryNoteRequest extends FormRequest
{
    use RejectsAttributionInput;
    use ValidatesQuotationWorkspace;

    /**
     * Needs the right to create delivery notes.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', DeliveryNote::class) ?? false;
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
            // Pending unless the POS marks it paid, which needs the method.
            'payment_status' => ['nullable', Rule::enum(\App\Enums\PaymentStatus::class)],
            'payment_method' => ['required_if:payment_status,paid', 'nullable', Rule::enum(\App\Enums\PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->workspaceMessages(), [
            'lines.required' => 'Add at least one item to the delivery note.',
            'lines.min' => 'Add at least one item to the delivery note.',
        ]);
    }
}