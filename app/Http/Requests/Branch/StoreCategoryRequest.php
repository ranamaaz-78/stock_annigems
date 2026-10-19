<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreCategoryRequest
 *
 * Create or update a global jewellery category.
 */
class StoreCategoryRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Actor may manage the global catalog.
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
        $ignoreId = $this->route('category') instanceof Category
            ? $this->route('category')->id
            : null;

        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('categories', 'name')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
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
            'name.required' => __('Enter a category name.'),
            'name.unique' => __('That category name already exists.'),
        ];
    }
}
