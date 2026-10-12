<?php

namespace App\Http\Requests\Branch;

use App\Enums\MakingChargeType;
use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\Performa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SettlePerformaRequest
 *
 * Payment against unpaid performa lines. Quantity cannot exceed outstanding;
 * jewellery is always qty 1. Prices use the same calculator as quotations
 * (gold rate + making + stones). A flat `price` is still accepted as a
 * fallback for loose stones and a fixed jewellery making charge.
 */
class SettlePerformaRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Take payment on an open performa.
     */
    public function authorize(): bool
    {
        $performa = $this->route('performa');

        return $performa instanceof Performa
            && ($this->user()?->can('settle', $performa) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.performa_item_id' => ['required', 'integer', 'exists:performa_items,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            // Flat amount kept so older clients / tests still settle.
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.price_per_piece' => ['nullable', 'numeric', 'min:0'],
            'lines.*.gold_rate_applied' => ['nullable', 'numeric', 'min:0'],
            'lines.*.making_charge_type' => ['nullable', Rule::enum(MakingChargeType::class)],
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
            'payment_method.required' => 'Select a payment method.',
            'lines.required' => 'Select at least one unpaid line to settle.',
            'lines.min' => 'Select at least one unpaid line to settle.',
            'lines.*.quantity.min' => 'Quantity must be at least 1.',
        ];
    }
}
