<?php

namespace App\Http\Requests\Branch;

use App\Models\Stone;
use App\Services\StoneImportService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreStoneImportRequest
 *
 * Final step of the Excel import: the rows as the operator left them in the
 * preview grid. Only shape is checked here — every figure is recalculated
 * server-side by StoneImportService before a single row is written. Rows
 * that still fail are not an error: they are set aside for download.
 */
class StoreStoneImportRequest extends FormRequest
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
            'token' => ['nullable', 'string', 'uuid'],
            'rows' => ['required', 'array', 'min:1', 'max:'.StoneImportService::MAX_ROWS],
            'rows.*' => ['array'],
            // Field-level checks (missing name, over-long text, bad figures)
            // are StoneImportService::evaluate()'s job, so one bad row is
            // reported instead of failing the whole request.
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => __('There is nothing to import.'),
            'rows.max' => __('Too many rows for one import. Split the sheet and try again.'),
        ];
    }
}
