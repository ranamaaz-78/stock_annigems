<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\BranchStatus;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateBranchRequest
 *
 * Validation for editing an existing branch.
 */
class UpdateBranchRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Only SuperAdmin may update branches.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Validation rules (unique rules ignore the current branch).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Integer id is used only to ignore this row in unique rules — it never
        // appears in the URL (the route binds on uuid via HasUuid).
        $branchId = $this->route('branch')?->id ?? $this->route('branch');

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('branches', 'name')->ignore($branchId)],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('branches', 'code')->ignore($branchId)],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('branches', 'email')->ignore($branchId)],
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
            'name.unique' => 'A branch with this name already exists.',
            'code.regex' => 'Branch code may only contain letters, numbers, and dashes.',
            'code.unique' => 'This branch code is already in use.',
            'contact_number.regex' => 'Enter a valid contact number.',
            'email.unique' => 'This branch email is already in use.',
        ];
    }
}
