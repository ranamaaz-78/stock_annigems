<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\BranchStatus;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreBranchRequest
 *
 * Validation for creating a new branch from the SuperAdmin panel.
 */
class StoreBranchRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Only SuperAdmin may create branches (also enforced by middleware/policy).
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:branches,name'],
            // Alphanumeric with dashes allowed for codes like GRW-01.
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/', 'unique:branches,code'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['nullable', 'email', 'max:255', 'unique:branches,email'],
            'status' => ['required', Rule::enum(BranchStatus::class)],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('A branch with this name already exists.'),
            'code.regex' => __('Branch code may only contain letters, numbers, and dashes.'),
            'code.unique' => __('This branch code is already in use.'),
            'contact_number.regex' => __('Enter a valid contact number.'),
            'email.unique' => __('This branch email is already in use.'),
        ];
    }
}
