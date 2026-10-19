<?php

namespace App\Services;

use App\Models\Category;
use App\Models\JewelleryItem;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CategoryService
 *
 * Global category + sub-category CRUD. Deletes are blocked while any
 * jewellery item (any branch) still references the row.
 */
class CategoryService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * Categories with sub-category and item counts.
     *
     * @return Collection<int, Category>
     */
    public function listCategories(): Collection
    {
        return Category::query()
            ->withCount(['subCategories', 'jewelleryItems'])
            ->ordered()
            ->get();
    }

    /**
     * Sub-categories of one parent.
     *
     * @return Collection<int, SubCategory>
     */
    public function listSubCategories(Category $category): Collection
    {
        return $category->subCategories()
            ->withCount('jewelleryItems')
            ->ordered()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data, User $actor): Category
    {
        $row = Category::query()->create($this->categoryPayload($data));
        $this->auditLogService->log('category.created', $row, null, $row->toArray(), $actor);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data, User $actor): Category
    {
        $old = $category->toArray();
        $category->fill($this->categoryPayload($data, $category));
        $category->save();
        $this->auditLogService->log('category.updated', $category, $old, $category->toArray(), $actor);

        return $category->refresh();
    }

    /**
     * Soft-delete when unused.
     */
    public function deleteCategory(Category $category, User $actor): void
    {
        $count = JewelleryItem::query()->withoutGlobalScopes()->where('category_id', $category->id)->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'name' => __(':category is used by :count jewellery item(s) and cannot be deleted. Deactivate it instead.', ['category' => $category->name, 'count' => $count]),
            ]);
        }

        if ($category->subCategories()->exists()) {
            throw ValidationException::withMessages([
                'name' => __(':category still has sub-categories. Remove those first, or deactivate the category.', ['category' => $category->name]),
            ]);
        }

        $old = $category->toArray();
        $category->delete();
        $this->auditLogService->log('category.deleted', $category, $old, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSubCategory(Category $category, array $data, User $actor): SubCategory
    {
        if (! $category->is_active) {
            throw ValidationException::withMessages([
                'category_id' => __('Sub-categories can only be added to an active category.'),
            ]);
        }

        $row = SubCategory::query()->create(array_merge($this->subPayload($data, null, (int) $category->id), [
            'category_id' => $category->id,
        ]));
        $this->auditLogService->log('sub_category.created', $row, null, $row->toArray(), $actor);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSubCategory(SubCategory $sub, array $data, User $actor): SubCategory
    {
        $old = $sub->toArray();
        $sub->fill($this->subPayload($data, $sub, (int) $sub->category_id));
        $sub->save();
        $this->auditLogService->log('sub_category.updated', $sub, $old, $sub->toArray(), $actor);

        return $sub->refresh();
    }

    /**
     * Soft-delete a sub-category when unused.
     */
    public function deleteSubCategory(SubCategory $sub, User $actor): void
    {
        $count = JewelleryItem::query()->withoutGlobalScopes()->where('sub_category_id', $sub->id)->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'name' => __(':sub is used by :count jewellery item(s) and cannot be deleted. Deactivate it instead.', ['sub' => $sub->name, 'count' => $count]),
            ]);
        }

        $old = $sub->toArray();
        $sub->delete();
        $this->auditLogService->log('sub_category.deleted', $sub, $old, null, $actor);
    }

    /**
     * How many items (any branch) reference this category.
     */
    public function categoryUsage(Category $category): int
    {
        return JewelleryItem::query()->withoutGlobalScopes()->where('category_id', $category->id)->count();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function categoryPayload(array $data, ?Category $existing = null): array
    {
        $name = trim((string) $data['name']);

        return [
            'name' => $name,
            'slug' => $this->uniqueSlug($name, $existing?->id),
            'description' => $data['description'] ?? null,
            'is_active' => $this->boolFlag($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function subPayload(array $data, ?SubCategory $existing = null, ?int $categoryId = null): array
    {
        $name = trim((string) $data['name']);
        $parentId = $categoryId ?? (int) $existing?->category_id;

        return [
            'name' => $name,
            'slug' => $this->uniqueSubSlug($name, $parentId, $existing?->id),
            'description' => $data['description'] ?? null,
            'is_active' => $this->boolFlag($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * Unique slug among non-deleted categories.
     */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 1;

        while (Category::query()->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * Unique slug among non-deleted subs of the same parent.
     */
    private function uniqueSubSlug(string $name, int $categoryId, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'sub-category';
        $slug = $base;
        $i = 1;

        while (SubCategory::query()
            ->where('category_id', $categoryId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * HTML "0"/"1" must not use PHP's (bool) cast — (bool) "0" is true.
     */
    private function boolFlag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
