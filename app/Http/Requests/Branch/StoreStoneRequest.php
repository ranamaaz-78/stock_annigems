<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use App\Http\Requests\Concerns\ValidatesStoneLot;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreStoneRequest
 *
 * One lot from the supplier sheet. stone_code, barcode, quantity, totals,
 * and uuid are issued or calculated server-side and are never in these rules.
 */
class StoreStoneRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;
    use ValidatesStoneLot;

    /**
     * Actor may register stones.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Stone::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->lotRules(), [
            'is_active' => ['sometimes', 'boolean'],
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
