<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\BranchStatus;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\User;
use App\Support\Settings\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreBranchAdminRequest
 *
 * Creates a Branch Admin. Role is NOT accepted from input — UserService sets ADMIN.
 * The branch is selected by public uuid (`branch`), then resolved to integer
 * branch_id inside UserService. Password may be auto-generated when password_mode is "auto".
 */
class StoreBranchAdminRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Only SuperAdmin may create Branch Admins.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('createBranchAdmin', User::class) ?? false;
    }

    /**
     * Prepare input: drop password fields when auto-generating.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('password_mode', 'auto') === 'auto') {
            $this->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'branch' => [
                'required',
                'uuid',
                Rule::exists('branches', 'uuid')->where(fn ($q) => $q->where('status', BranchStatus::ACTIVE->value)->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'password_mode' => ['required', Rule::in(['auto', 'manual'])],
            'send_credentials_email' => ['sometimes', 'boolean'],
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
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch.required' => __('Select a branch.'),
            'branch.uuid' => __('Select an active branch.'),
            'branch.exists' => __('Select an active branch.'),
            'email.unique' => __('This email is already registered.'),
            'phone.regex' => __('Enter a valid phone number.'),
            'password.required' => __('Enter a password or choose auto-generate.'),
        ];
    }
}
