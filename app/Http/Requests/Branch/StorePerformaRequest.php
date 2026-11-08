<?php

namespace App\Http\Requests\Branch;

use App\Enums\DiscountType;
use App\Enums\LineType;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\ValidatesQuotationWorkspace;
use App\Models\Performa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StorePerformaRequest
 *
 * Issue payload: an existing customer (or a new one's name and phone) +
 * scanned lines. Prices are worked out on the server for the printed memo,
 * with the discount and tax chosen here; the settlement invoice still owns
 * the money.
 */
class StorePerformaRequest extends FormRequest
{
    use RejectsAttributionInput;
    use ValidatesQuotationWorkspace;

    /**
     * Issue a performa.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Performa::class) ?? false;
    }

    /**
     * Resolve public uuids to integer PKs before the integer id rules run.
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
        return [
            // An existing customer, or the details of a new one (the POS saves
            // someone it has not seen before as a customer).
            'walkin_name' => ['nullable', 'string', 'max:150', 'required_without:customer_uuid'],
            'walkin_phone' => [
                'nullable',
                'string',
                'max:30',
                'required_without:customer_uuid',
                'regex:/^[0-9+\-\s()]{7,30}$/',
            ],
            'walkin_email' => ['nullable', 'email', 'max:150'],
            'walkin_address' => ['nullable', 'string', 'max:500'],
            // Set by the POS when a loaded customer's fields were open for editing.
            'update_customer' => ['nullable', 'boolean'],
            'walkin_nie' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s.\/]{5,20}$/'],
            'customer_uuid' => [
                'nullable',
                'required_without:walkin_phone',
                'uuid',
                // Customers are global (not branch-scoped); trashed ones cannot be picked.
                Rule::exists('customers', 'uuid')->whereNull('deleted_at'),
            ],
            'performa_date' => ['required', 'date'],
            // Discount and tax, chosen as on an invoice. The IVA and recargo are named by
            // uuid and their percentages are looked up on the server.
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'apply_tax' => ['sometimes', 'boolean'],
            'tax_rate_uuid' => ['nullable', 'uuid', Rule::exists('tax_rates', 'uuid')->where('kind', 'iva')->whereNull('deleted_at')],
            'recargo_rate_uuid' => ['nullable', 'uuid', Rule::exists('tax_rates', 'uuid')->where('kind', 'recargo')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_type' => ['required', Rule::enum(LineType::class)],
            'lines.*.jewellery_item_id' => [
                'exclude_unless:lines.*.line_type,'.LineType::JEWELLERY_ITEM->value,
                'required',
                'integer',
            ],
            'lines.*.stone_id' => [
                'exclude_unless:lines.*.line_type,'.LineType::LOOSE_STONE->value,
                'required',
                'integer',
            ],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.weight_grams' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:9999999'],
            // Optional pricing, used only when the performa prints its prices.
            'lines.*.price_per_piece' => ['nullable', 'numeric', 'min:0'],
            'lines.*.gold_rate_applied' => ['nullable', 'numeric', 'min:0'],
            'lines.*.making_charge_type' => ['nullable', 'string', 'max:20'],
            'lines.*.making_charge_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.stones' => ['nullable', 'array'],
            'lines.*.stones.*.jewellery_item_stone_id' => ['nullable', 'integer'],
            'lines.*.stones.*.price_per_piece' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_uuid.required_without' => __('Select a customer or enter a name and phone.'),
            'customer_uuid.exists' => __('Select an existing customer.'),
            'walkin_name.required_without' => __('Select a customer or enter a walk-in name and phone.'),
            'walkin_phone.required_without' => __('Enter the walk-in customer phone.'),
            'lines.required' => __('Add at least one item to the performa.'),
            'lines.min' => __('Add at least one item to the performa.'),
            'performa_date.required' => __('Performa date is required.'),
            'lines.*.jewellery_item_id.required' => __('A scanned item is missing its reference. Remove the line and scan it again.'),
            'lines.*.jewellery_item_id.integer' => __('A scanned item is missing its reference. Remove the line and scan it again.'),
            'lines.*.stone_id.required' => __('A loose stone line is missing its reference. Remove the line and scan it again.'),
            'lines.*.stone_id.integer' => __('A loose stone line is missing its reference. Remove the line and scan it again.'),
        ];
    }
}
