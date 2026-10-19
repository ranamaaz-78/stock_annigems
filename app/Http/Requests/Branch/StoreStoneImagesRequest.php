<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreStoneImagesRequest
 *
 * Upload into the Stones image folder. Content checks and the unique
 * filename live in StoneImageService; this request only bounds the batch.
 */
class StoreStoneImagesRequest extends FormRequest
{
    /**
     * Folder permission is enforced by the controller policy.
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
        $maxKb = (int) config('annigems.stones.image_max_kilobytes', 5120);

        return [
            'files' => ['required', 'array', 'min:1', 'max:40'],
            // What to do with a name already in the folder; absent means report it.
            'on_duplicate' => ['nullable', 'in:skip,replace'],
            'files.*' => ['required', 'file', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxKb = (int) config('annigems.stones.image_max_kilobytes', 5120);

        return [
            'files.required' => __('Choose at least one image.'),
            'files.max' => __('Upload at most 40 images at a time.'),
            'files.*.required' => __('Choose an image file.'),
            'files.*.file' => __('Each upload must be a file.'),
            'files.*.max' => __('Each image must be at most :maxKb KB.', ['maxKb' => $maxKb]),
        ];
    }
}
