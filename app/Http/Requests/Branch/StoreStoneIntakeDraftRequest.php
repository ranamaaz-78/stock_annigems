<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreStoneIntakeDraftRequest
 *
 * Server-side autosave of the working scan list.
 */
class StoreStoneIntakeDraftRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Same permission as performing intake.
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
        $qtyMax = (int) config('annigems.stones.intake_quantity_max', 10000);

        return [
            'intake_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'beep_muted' => ['sometimes', 'boolean'],
            'rows' => ['present', 'array'],
            'rows.*.uuid' => ['required', 'uuid'],
            // A per-gram row has no pieces, and its grams may still be blank.
            'rows.*.quantity' => ['nullable', 'integer', 'min:0', 'max:'.$qtyMax],
            'rows.*.weight_grams' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rows.*.pricing_mode' => ['nullable', 'string', 'max:20'],
            'rows.*.scanned_count' => ['nullable', 'integer', 'min:0'],
            // Display snapshots so a restored draft can render without extra lookups.
            'rows.*.name' => ['nullable', 'string', 'max:150'],
            'rows.*.stone_code' => ['nullable', 'string', 'max:20'],
            'rows.*.barcode' => ['nullable', 'string', 'max:64'],
            'rows.*.carat' => ['nullable', 'string', 'max:16'],
            'rows.*.shape' => ['nullable', 'string', 'max:60'],
            'rows.*.dimension' => ['nullable', 'string', 'max:40'],
            'rows.*.type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
