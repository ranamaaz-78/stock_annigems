<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Support\Settings\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * UpdateProfileRequest
 *
 * Self-service profile update: name, phone, optional password change.
 * Role, permissions, branch, and status are never accepted.
 */
class UpdateProfileRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Any authenticated branch user may update their own profile.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $changingPassword = $this->filled('new_password');

        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
        ];

        if ($changingPassword) {
            $rules['current_password'] = ['required', 'string'];
            $rules['new_password'] = [
                'required',
                'confirmed',
                PasswordRules::rule(),
            ];
        }

        return $rules;
    }

    /**
     * Verify current password when changing password.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('new_password') && ! Hash::check($this->input('current_password'), $this->user()->password)) {
                $validator->errors()->add('current_password', 'Current password is incorrect.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number.',
            'current_password.required' => 'Enter your current password to set a new one.',
            'new_password.confirmed' => 'New password confirmation does not match.',
        ];
    }
}
