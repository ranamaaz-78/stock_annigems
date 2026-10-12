<?php

namespace App\Http\Requests\SuperAdmin;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreGoldRatesRequest
 *
 * Validates SuperAdmin gold rate updates. Each active purity may receive a
 * new rate; rows are inserted, never updated in place.
 */
class StoreGoldRatesRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * SuperAdmin with gold_rates.manage may submit.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('manage', \App\Models\GoldRate::class) ?? false);
    }

    /**
     * Drop blank rate boxes before validation.
     *
     * The form renders an input for every active purity, but SuperAdmin may
     * update only some of them. Without this, the untouched (empty) boxes
     * would fail the `required` rule on `rates.*` and block the whole save.
     */
    protected function prepareForValidation(): void
    {
        $rates = $this->input('rates');

        if (! is_array($rates)) {
            return;
        }

        $this->merge([
            'rates' => array_filter(
                $rates,
                static fn ($rate): bool => $rate !== null && $rate !== '' && $rate !== false
            ),
        ]);
    }

    /**
     * Validation rules for bulk rate insert.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (float) config('annigems.gold_rates.rate_max', 999999.99);

        return [
            'effective_date' => ['required', 'date', 'before_or_equal:today'],
            'confirm_deviation' => ['sometimes', 'boolean'],
            // Keyed by purity id; at least one purity must carry a new rate.
            'rates' => ['required', 'array', 'min:1'],
            'rates.*' => ['required', 'numeric', 'gt:0', 'max:'.$max],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_date.required' => 'Please choose the date these rates take effect.',
            'effective_date.before_or_equal' => 'The effective date cannot be in the future.',
            'rates.required' => 'Enter at least one gold rate.',
            'rates.*.required' => 'Enter a rate or leave the field blank.',
            'rates.*.numeric' => 'The rate must be a number.',
            'rates.*.gt' => 'Each rate must be greater than zero.',
            'rates.*.max' => 'The rate entered is unrealistically high. Please check for a typo.',
        ];
    }

    /**
     * Normalised rates keyed by purity id.
     *
     * @return array<int, float>
     */
    public function ratesByPurity(): array
    {
        $normalised = [];

        foreach ((array) $this->input('rates', []) as $purityId => $rate) {
            if ($rate === null || $rate === '') {
                continue;
            }
            $normalised[(int) $purityId] = (float) $rate;
        }

        return $normalised;
    }
}
