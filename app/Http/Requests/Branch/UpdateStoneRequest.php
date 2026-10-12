<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\ValidatesStoneLot;
use App\Models\Stone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateStoneRequest
 *
 * Lot edit. Quantity, totals, and codes are not accepted from input — the
 * piece count is corrected through the stock ledger by StoneService.
 */
class UpdateStoneRequest extends FormRequest
{
    use RejectsAttributionInput;
    use ValidatesStoneLot;

    /**
     * Actor may edit this stone.
     */
    public function authorize(): bool
    {
        $stone = $this->route('stone');

        return $stone instanceof Stone && ($this->user()?->can('update', $stone) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->lotRules(), [
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->lotMessages();
    }
}
