<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LookupStoneScanRequest
 *
 * Barcode / stone-code lookup from the scan field.
 */
class LookupStoneScanRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Actor may perform intake (lookup is part of that workflow).
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
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => __('Scan or type a barcode.'),
        ];
    }
}
