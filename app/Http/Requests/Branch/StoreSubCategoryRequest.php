<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreSubCategoryRequest
 *
 * Unique name within the parent category.
 */
class StoreSubCategoryRequest extends FormRequest
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
        $category = $this->route('category');
        $categoryId = $category instanceof Category ? $category->id : 0;
        $ignoreId = $this->route('subCategory') instanceof SubCategory
            ? $this->route('subCategory')->id
            : null;

        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('sub_categories', 'name')
                    ->ignore($ignoreId)
                    ->where('category_id', $categoryId)
                    ->whereNull('deleted_at'),
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
            'name.required' => 'Enter a sub-category name.',
            'name.unique' => 'That name already exists in this category.',
        ];
    }
}
