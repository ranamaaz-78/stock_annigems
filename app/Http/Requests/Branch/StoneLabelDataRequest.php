<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoneLabelDataRequest
 *
 * The stones chosen for the label print dialog. Permission is enforced by
 * the controller policy, per stone, so one stone from another branch fails
 * the whole request rather than being silently dropped.
 */
class StoneLabelDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuids' => ['required', 'array', 'min:1', 'max:300'],
            'uuids.*' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uuids.required' => __('Select at least one stone to print.'),
            'uuids.max' => __('Print at most 300 stones at a time.'),
        ];
    }
}
