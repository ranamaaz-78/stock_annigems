<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RenameStoneFolderRequest
 *
 * New label for a material folder. Clashes with another folder are checked in
 * StoneFolderService so the message can name the folder in the way.
 */
class RenameStoneFolderRequest extends FormRequest
{
    /**
     * Manage permission is enforced by the controller policy.
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
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a folder name.',
            'name.max' => 'Folder name must be 120 characters or fewer.',
        ];
    }
}
