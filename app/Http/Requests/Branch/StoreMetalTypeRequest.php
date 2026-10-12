<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use App\Models\Category;
use App\Models\MetalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreMetalTypeRequest
 *
 * Global metal vocabulary create/update.
 */
class StoreMetalTypeRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Metals share the categories.manage permission (global catalog).
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
        $ignoreId = $this->route('metalType') instanceof MetalType
            ? $this->route('metalType')->id
            : null;

        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('metal_types', 'name')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
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
            'name.required' => 'Enter a metal name.',
            'name.unique' => 'That metal already exists.',
        ];
    }
}
