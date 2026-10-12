<?php

namespace App\Http\Requests\Branch;

use App\Enums\JewelleryItemStatus;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\RespondsWithJsonValidation;
use App\Models\JewelleryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreJewelleryItemRequest
 *
 * Create payload. item_code, barcode, qr_token, and stone totals are
 * stripped. Metal weight is typed by staff and stored as net_weight.
 */
class StoreJewelleryItemRequest extends FormRequest
{
    use RejectsAttributionInput;
    use RespondsWithJsonValidation;

    /**
     * Actor may create jewellery.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', JewelleryItem::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->itemRules();
    }

    /**
     * Shared create/update field rules.
     *
     * @return array<string, mixed>
     */
    public function itemRules(): array
    {
        $grossMax = (float) config('annigems.jewellery.gross_weight_max', 5000);
        $qtyMax = (int) config('annigems.jewellery.allocation_quantity_max', 10000);
        $imageMax = (int) config('annigems.jewellery.image_max_kilobytes', 2048);
        $imageCount = (int) config('annigems.jewellery.image_max_count', 8);

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', 1)->whereNull('deleted_at')],
            'sub_category_id' => ['nullable', 'integer', 'exists:sub_categories,id'],
            'name' => ['required', 'string', 'max:200'],
            'metal_type_id' => ['required', 'integer', Rule::exists('metal_types', 'id')->where('is_active', 1)->whereNull('deleted_at')],
            'purity_id' => ['required', 'integer', 'exists:purities,id'],
            'gross_weight' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:'.$grossMax],
            // Sale/net weight is the metal grams staff type — never derived from stones.
            'metal_weight' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:'.$grossMax, 'lte:gross_weight'],
            'status' => ['required', Rule::in(JewelleryItemStatus::usableValues())],
            'description' => ['nullable', 'string', 'max:2000'],
            'stones' => ['nullable', 'array'],
            'stones.*.uuid' => ['required', 'uuid'],
            'stones.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$qtyMax],
            'images' => ['nullable', 'array', 'max:'.$imageCount],
            'images.*' => ['file', 'max:'.$imageMax],
            'primary_image_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'Select a category.',
            'category_id.exists' => 'Select an active category.',
            'name.required' => 'Enter an item name.',
            'metal_type_id.required' => 'Select a metal type.',
            'purity_id.required' => 'Select a purity.',
            'gross_weight.required' => 'Enter gross weight in grams.',
            'gross_weight.gt' => 'Gross weight must be greater than zero.',
            'gross_weight.max' => 'Gross weight is unusually high. Check for a typing error.',
            'gross_weight.decimal' => 'Gross weight allows at most three decimal places.',
            'metal_weight.required' => 'Enter metal weight in grams.',
            'metal_weight.gt' => 'Metal weight must be greater than zero.',
            'metal_weight.max' => 'Metal weight is unusually high. Check for a typing error.',
            'metal_weight.decimal' => 'Metal weight allows at most three decimal places.',
            'metal_weight.lte' => 'Metal weight cannot be greater than gross weight.',
            'status.in' => 'That status cannot be set from jewellery management.',
            'stones.*.quantity.min' => 'Stone quantity must be at least 1.',
        ];
    }
}
