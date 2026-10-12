<?php

namespace App\Http\Requests\Branch;

use App\Enums\LineType;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\ValidatesQuotationWorkspace;
use App\Models\Performa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StorePerformaRequest
 *
 * Issue payload: an existing creditor (or a new one's details) + scanned
 * lines. Prices are worked out on the server for the printed memo; the
 * settlement invoice still owns the money. Walk-in / sales-customer
 * attach is not accepted.
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
        $branchId = (int) $this->user()?->branch_id;

        return [
            // An existing creditor, or the details of a new one (the POS saves
            // someone it has not seen before as a creditor).
            'walkin_name' => ['nullable', 'string', 'max:150', 'required_without:creditor_uuid'],
            'walkin_phone' => [
                'nullable',
                'string',
                'max:30',
                'required_without:creditor_uuid',
                'regex:/^[0-9+\-\s()]{7,30}$/',
            ],
            'walkin_email' => ['nullable', 'email', 'max:150'],
            'creditor_uuid' => [
                'nullable',
                'required_without:walkin_phone',
                'uuid',
                // Active creditors on this branch only — never a sales customer.
                Rule::exists('creditors', 'uuid')->where(
                    fn ($query) => $query
                        ->where('branch_id', $branchId)
                        ->where('status', true)
                        ->whereNull('deleted_at')
                ),
            ],
            'performa_date' => ['required', 'date'],
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
            'creditor_uuid.required' => 'Select a creditor. Register them under Creditors first.',
            'creditor_uuid.exists' => 'Select an active creditor from this branch.',
            'lines.required' => 'Add at least one item to the performa.',
            'lines.min' => 'Add at least one item to the performa.',
            'performa_date.required' => 'Performa date is required.',
            'lines.*.jewellery_item_id.required' => 'A scanned item is missing its reference. Remove the line and scan it again.',
            'lines.*.jewellery_item_id.integer' => 'A scanned item is missing its reference. Remove the line and scan it again.',
            'lines.*.stone_id.required' => 'A loose stone line is missing its reference. Remove the line and scan it again.',
            'lines.*.stone_id.integer' => 'A loose stone line is missing its reference. Remove the line and scan it again.',
        ];
    }
}
