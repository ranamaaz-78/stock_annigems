<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateBranchContactRequest
 *
 * Edits the Branch record itself. These values are not duplicated into
 * branch_settings — the name printed on an invoice is branches.name.
 */
class UpdateBranchContactRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Admin of this branch.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageBranchSettings') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the name printed on documents.',
            'address.required' => 'Enter the branch address.',
            'city.required' => 'Enter the city.',
            'contact_number.required' => 'Enter the branch phone number.',
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
