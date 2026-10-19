<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreStoneIntakeRequest
 *
 * Final save of a scan batch. Stones are identified by uuid only.
 */
class StoreStoneIntakeRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Actor may perform intake.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\StoneIntake::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $qtyMax = (int) config('annigems.stones.intake_quantity_max', 10000);

        return [
            'intake_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.uuid' => ['required', 'uuid'],
            // Pieces for a piece or carat lot, grams for a per-gram lot; the
            // service checks the one that lot is counted in.
            'rows.*.quantity' => ['required_without:rows.*.weight_grams', 'nullable', 'integer', 'min:1', 'max:'.$qtyMax],
            'rows.*.weight_grams' => ['required_without:rows.*.quantity', 'nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:9999999'],
            'rows.*.scanned_count' => ['nullable', 'integer', 'min:0', 'max:'.$qtyMax],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => __('Scan or add at least one stone before saving.'),
            'rows.min' => __('Scan or add at least one stone before saving.'),
            'intake_date.before_or_equal' => __('Intake date cannot be in the future.'),
            'rows.*.quantity.max' => __('Quantity is unusually high. Check for a stuck scanner or typing error.'),
            'rows.*.quantity.required_without' => __('Enter a quantity for every stone.'),
            'rows.*.weight_grams.required_without' => __('Enter the grams for every per-gram stone.'),
            'rows.*.weight_grams.gt' => __('Grams must be greater than zero.'),
            'rows.*.weight_grams.decimal' => __('Grams allow at most three decimal places.'),
        ];
    }
}
