<?php

namespace App\Http\Requests\Branch;

use App\Models\Stone;
use App\Services\StoneImportService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PreviewStoneImportRequest
 *
 * Step two of the Excel import: which sheet column feeds which of our fields.
 */
class PreviewStoneImportRequest extends FormRequest
{
    /**
     * Importing creates stones, so it needs the same permission.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Stone::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'uuid'],
            // Unknown field keys are stripped in prepareForValidation, so by
            // this point every entry is one of ours pointing at a column index.
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * Strip unknown field keys before validation rather than failing on them.
     */
    protected function prepareForValidation(): void
    {
        $mapping = (array) $this->input('mapping', []);

        $this->merge([
            'mapping' => array_intersect_key($mapping, StoneImportService::FIELDS),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => __('Upload the spreadsheet again.'),
            'mapping.required' => __('Confirm which column holds each field.'),
        ];
    }
}
