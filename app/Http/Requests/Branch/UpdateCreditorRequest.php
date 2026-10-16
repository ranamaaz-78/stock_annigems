<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\Creditor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateCreditorRequest
 *
 * Validates creditor edits with per-branch phone uniqueness.
 */
class UpdateCreditorRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Branch staff with creditors.edit may update this creditor.
     */
    public function authorize(): bool
    {
        $creditor = $this->route('creditor');

        return $creditor instanceof Creditor
            && ($this->user()?->can('update', $creditor) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Creditor $creditor */
        $creditor = $this->route('creditor');
        $branchId = (int) $this->user()?->branch_id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => [
                'required',
                'string',
                'max:30',
                'regex:/^[0-9+\-\s()]{7,30}$/',
                Rule::unique('creditors', 'phone')
                    ->ignore($creditor->id)
                    ->where(fn ($query) => $query->where('branch_id', $branchId)),
            ],
            'alternate_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'nie' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s.\/]{5,20}$/'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new StoreCreditorRequest)->messages();
    }
}
