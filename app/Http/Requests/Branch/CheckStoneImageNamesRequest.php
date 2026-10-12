<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CheckStoneImageNamesRequest
 *
 * The file names about to be uploaded, sent first so the operator can be
 * asked what to do with the ones already in the folder. Folder permission
 * is enforced by the controller policy.
 */
class CheckStoneImageNamesRequest extends FormRequest
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
            'names' => ['required', 'array', 'min:1', 'max:40'],
            'names.*' => ['required', 'string', 'max:255'],
        ];
    }
}
