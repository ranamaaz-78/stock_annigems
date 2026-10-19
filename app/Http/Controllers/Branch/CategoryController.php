<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreCategoryRequest;
use App\Http\Requests\Branch\StoreSubCategoryRequest;
use App\Models\Category;
use App\Models\SubCategory;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * CategoryController
 *
 * Split-page CRUD for global categories and their sub-categories.
 */
class CategoryController extends Controller
{
    use RespondsWithJson;

    public function __construct(private CategoryService $categoryService) {}

    /**
     * Split layout: categories left, subs right.
     */
    public function index(): View
    {
        $this->authorize('viewAny', Category::class);

        $categories = $this->categoryService->listCategories();
        $selected = $categories->first();

        return view('branch.categories.index', [
            'categories' => $categories,
            'selected' => $selected,
            'subCategories' => $selected ? $this->categoryService->listSubCategories($selected) : collect(),
            'canManage' => auth()->user()?->can('manage', Category::class) ?? false,
        ]);
    }

    /**
     * Sub-categories JSON for the selected category (no page reload).
     */
    public function subCategories(Category $category): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $rows = $this->categoryService->listSubCategories($category)->map(fn (SubCategory $sub): array => [
            'uuid' => $sub->uuid,
            'name' => $sub->name,
            'description' => $sub->description,
            'is_active' => $sub->is_active,
            'sort_order' => $sub->sort_order,
            'items_count' => $sub->jewellery_items_count ?? 0,
        ]);

        return $this->jsonSuccess(__('OK.'), [
            'category' => [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'usage' => $this->categoryService->categoryUsage($category),
            ],
            'sub_categories' => $rows,
        ]);
    }

    /**
     * Create a category.
     */
    public function store(StoreCategoryRequest $request): RedirectResponse|JsonResponse
    {
        $row = $this->categoryService->createCategory($request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess(__('Category saved.'), ['uuid' => $row->uuid], 201);
        }

        return redirect()->route('branch.categories.index')->with('success', __('Category saved.'));
    }

    /**
     * Update a category.
     */
    public function update(StoreCategoryRequest $request, Category $category): RedirectResponse|JsonResponse
    {
        $this->categoryService->updateCategory($category, $request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess(__('Category updated.'));
        }

        return redirect()->route('branch.categories.index')->with('success', __('Category updated.'));
    }

    /**
     * Soft-delete when unused.
     */
    public function destroy(Category $category): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', Category::class);

        try {
            $this->categoryService->deleteCategory($category, request()->user());
        } catch (ValidationException $e) {
            if (request()->expectsJson()) {
                return $this->jsonFailure(collect($e->errors())->flatten()->first() ?? $e->getMessage(), $e->errors());
            }
            throw $e;
        }

        if (request()->expectsJson()) {
            return $this->jsonSuccess(__('Category deleted.'));
        }

        return redirect()->route('branch.categories.index')->with('success', __('Category deleted.'));
    }

    /**
     * Create a sub-category under the selected parent.
     */
    public function storeSub(StoreSubCategoryRequest $request, Category $category): RedirectResponse|JsonResponse
    {
        $row = $this->categoryService->createSubCategory($category, $request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess(__('Sub-category saved.'), ['uuid' => $row->uuid], 201);
        }

        return redirect()->route('branch.categories.index')->with('success', __('Sub-category saved.'));
    }

    /**
     * Update a sub-category.
     */
    public function updateSub(StoreSubCategoryRequest $request, Category $category, SubCategory $subCategory): RedirectResponse|JsonResponse
    {
        abort_unless((int) $subCategory->category_id === (int) $category->id, 404);
        $this->categoryService->updateSubCategory($subCategory, $request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->jsonSuccess(__('Sub-category updated.'));
        }

        return redirect()->route('branch.categories.index')->with('success', __('Sub-category updated.'));
    }

    /**
     * Soft-delete a sub-category when unused.
     */
    public function destroySub(Category $category, SubCategory $subCategory): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', Category::class);
        abort_unless((int) $subCategory->category_id === (int) $category->id, 404);

        try {
            $this->categoryService->deleteSubCategory($subCategory, request()->user());
        } catch (ValidationException $e) {
            if (request()->expectsJson()) {
                return $this->jsonFailure(collect($e->errors())->flatten()->first() ?? $e->getMessage(), $e->errors());
            }
            throw $e;
        }

        if (request()->expectsJson()) {
            return $this->jsonSuccess(__('Sub-category deleted.'));
        }

        return redirect()->route('branch.categories.index')->with('success', __('Sub-category deleted.'));
    }
}
