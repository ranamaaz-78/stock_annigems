<?php

namespace App\Http\Requests\Branch;

use App\Models\Stone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StageStoneImportRequest
 *
 * Step one of the Excel import: the workbook itself.
 */
class StageStoneImportRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                // Extension list rather than the `mimes` rule: Excel files are
                // reported under several MIME types depending on the client.
                'extensions:xlsx,xls,csv',
                'max:'.(int) config('annigems.stones.import_max_kilobytes', 10240),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a spreadsheet to import.',
            'file.extensions' => 'Upload an .xlsx, .xls, or .csv file.',
            'file.max' => 'That file is too large to import.',
        ];
    }
}
