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
 * Issue payload: an existing creditor + scanned lines. No prices — those
 * are captured only when the creditor pays. Walk-in / sales-customer
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
            'creditor_uuid' => [
                'required',
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
