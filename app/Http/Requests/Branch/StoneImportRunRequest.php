<?php

namespace App\Http\Requests\Branch;

use App\Models\Stone;
use App\Services\StoneImportService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoneImportRunRequest
 *
 * The three calls that run an import in batches: begin, chunk, finish.
 * One class, with rules chosen by which route was hit. Only shape is checked;
 * every row is re-costed server-side before a stone is written, and a bad
 * row is set aside rather than failing the request.
 */
class StoneImportRunRequest extends FormRequest
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
        $routeName = (string) $this->route()?->getName();

        return match (true) {
            str_ends_with($routeName, '.begin') => [
                'filename' => ['nullable', 'string', 'max:255'],
                'total_rows' => ['required', 'integer', 'min:1', 'max:'.StoneImportService::MAX_ROWS],
            ],
            str_ends_with($routeName, '.chunk') => [
                'import_uuid' => ['required', 'string', 'uuid'],
                'chunk_index' => ['required', 'integer', 'min:0'],
                'rows' => ['required', 'array', 'min:1', 'max:'.(int) config('annigems.stones.import_chunk_max', 500)],
                'rows.*' => ['array'],
            ],
            default => [
                'import_uuid' => ['required', 'string', 'uuid'],
                'token' => ['nullable', 'string', 'uuid'],
                'rejected_rows' => ['nullable', 'array', 'max:'.StoneImportService::MAX_ROWS],
                'rejected_rows.*' => ['array'],
                // Original sheet header per field, used to name the columns
                // of the rows-to-fix workbook.
                'headers' => ['nullable', 'array'],
                'headers.*' => ['nullable', 'string', 'max:180'],
                // Field => column index, so the file can keep the sheet's layout.
                'mapping' => ['nullable', 'array'],
                'mapping.*' => ['nullable', 'integer', 'min:0'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'total_rows.required' => __('There is nothing to import.'),
            'total_rows.max' => __('Too many rows for one import. Split the sheet and try again.'),
            'rows.required' => __('There is nothing to import.'),
            'rows.max' => __('That batch is too large.'),
        ];
    }
}
