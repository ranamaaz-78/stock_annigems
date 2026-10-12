<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Http\Requests\Concerns\ValidatesQuotationWorkspace;
use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreQuotationRequest
 *
 * Validates quotation create/update payload. Monetary figures are recalculated
 * server-side — submitted totals are not trusted.
 */
class StoreQuotationRequest extends FormRequest
{
    use RejectsAttributionInput;
    use ValidatesQuotationWorkspace;

    /**
     * Create or update draft quotation.
     */
    public function authorize(): bool
    {
        $quotation = $this->route('quotation');

        if ($quotation instanceof Quotation) {
            return $this->user()?->can('update', $quotation) ?? false;
        }

        return $this->user()?->can('create', Quotation::class) ?? false;
    }

    /**
     * Resolve public uuids to integer PKs before the integer id rules run.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareWorkspaceLines();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->workspaceRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->workspaceMessages();
    }
}
