<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Support\Settings\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ChangePasswordRequest
 *
 * Strong password rules for forced first-login and voluntary password changes.
 */
class ChangePasswordRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'confirmed',
                PasswordRules::rule(),
            ],
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
            'password.required' => 'A new password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }
}
