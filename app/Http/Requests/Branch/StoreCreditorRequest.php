<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\Creditor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreCreditorRequest
 *
 * Validates new branch-scoped creditor registration. Phone uniqueness is
 * per branch — the same number may exist at another branch.
 */
class StoreCreditorRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Branch staff with creditors.create may register creditors.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Creditor::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $branchId = (int) $this->user()?->branch_id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => [
                'required',
                'string',
                'max:30',
                'regex:/^[0-9+\-\s()]{7,30}$/',
                // Same dealer number is allowed at another branch.
                Rule::unique('creditors', 'phone')->where(
                    fn ($query) => $query->where('branch_id', $branchId)
                ),
            ],
            'alternate_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'email' => ['nullable', 'email', 'max:150'],
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
        return [
            'name.required' => 'Please enter the creditor name.',
            'phone.required' => 'Phone number is required.',
            'phone.unique' => 'This phone number is already registered to another creditor at this branch.',
            'phone.regex' => 'Enter a valid phone number.',
            'alternate_phone.regex' => 'Enter a valid alternate phone number.',
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
