<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * UpdateBranchStaffRequest
 *
 * Updates branch staff. Rejects admin/superadmin role values server-side.
 */
class UpdateBranchStaffRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Branch Admin with users.edit permission on the target user.
     */
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'permissions' => array_values(array_filter((array) $this->input('permissions', []))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'role' => ['required', Rule::in(PermissionService::ASSIGNABLE_STAFF_ROLES)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('This email is already registered.'),
            'phone.regex' => __('Enter a valid phone number.'),
            'role.in' => __('Select a valid staff role.'),
            'permissions.*.exists' => __('One or more selected permissions are invalid.'),
        ];
    }
}
