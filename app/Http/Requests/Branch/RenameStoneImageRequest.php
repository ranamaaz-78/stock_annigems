<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RenameStoneImageRequest
 *
 * New display name for one picture. Uniqueness and the image extension
 * are checked in StoneImageService so the message can name the collision.
 */
class RenameStoneImageRequest extends FormRequest
{
    /**
     * Update permission is enforced by the controller policy.
     */
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
            'name' => ['required', 'string', 'max:180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a file name.',
            'name.max' => 'File name must be 180 characters or fewer.',
        ];
    }
}
