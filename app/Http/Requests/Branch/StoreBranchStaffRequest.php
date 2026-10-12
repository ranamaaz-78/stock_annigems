<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Settings\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreBranchStaffRequest
 *
 * Creates branch staff (Manager, Cashier, Inventory Staff). Role and branch_id
 * are validated here but assigned server-side in BranchStaffService.
 */
class StoreBranchStaffRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Branch Admin with users.create permission.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Drop password fields when auto-generating.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('password_mode', 'auto') === 'auto') {
            $this->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }

        $this->merge([
            'permissions' => array_values(array_filter((array) $this->input('permissions', []))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'role' => ['required', Rule::in(PermissionService::ASSIGNABLE_STAFF_ROLES)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password_mode' => ['required', Rule::in(['auto', 'manual'])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];

        if ($this->input('password_mode') === 'manual') {
            $rules['password'] = [
                'required',
                'confirmed',
                PasswordRules::rule(),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
            'phone.regex' => 'Enter a valid phone number.',
            'role.in' => 'Select a valid staff role.',
            'password.required' => 'Enter a password or choose auto-generate.',
            'permissions.*.exists' => 'One or more selected permissions are invalid.',
        ];
    }
}
