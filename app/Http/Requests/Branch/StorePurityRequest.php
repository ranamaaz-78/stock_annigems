<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use App\Models\Category;
use App\Models\Purity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StorePurityRequest
 *
 * Purity is unique within its metal type. value is stored unused until Quotation.
 */
class StorePurityRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Global catalog permission.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Category::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ignoreId = $this->route('purity') instanceof Purity
            ? $this->route('purity')->id
            : null;
        $metalId = (int) $this->input('metal_type_id');

        return [
            'metal_type_id' => ['required', 'integer', 'exists:metal_types,id'],
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('purities', 'name')
                    ->ignore($ignoreId)
                    ->where('metal_type_id', $metalId)
                    ->whereNull('deleted_at'),
            ],
            'value' => ['required', 'numeric', 'gt:0', 'lte:1', 'decimal:0,4'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'metal_type_id.required' => 'Select a metal type.',
            'name.required' => 'Enter a purity name (e.g. 22K).',
            'name.unique' => 'That purity already exists for this metal.',
            'value.required' => 'Enter the fineness ratio (e.g. 0.9166).',
            'value.lte' => 'Fineness cannot be greater than 1.',
        ];
    }
}
