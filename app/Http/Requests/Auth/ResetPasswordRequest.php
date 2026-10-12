<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Support\Settings\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ResetPasswordRequest
 *
 * Validates token-based password reset submissions.
 */
class ResetPasswordRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required'],
            'email' => ['required', 'email'],
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
